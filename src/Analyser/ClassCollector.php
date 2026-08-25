<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\PhpParser\VisibilityFlagChecker;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function end;
use function in_array;
use function is_int;
use function is_string;
use function preg_match;
use function spl_object_id;
use function str_starts_with;
use function strcasecmp;
use function strpos;
use function strtolower;
use function substr;

final class ClassCollector extends NodeVisitorAbstract
{
    private const SUPERGLOBALS = [
        '_GET'     => true,
        '_POST'    => true,
        '_REQUEST' => true,
        '_SESSION' => true,
        '_COOKIE'  => true,
        '_SERVER'  => true,
        '_ENV'     => true,
        '_FILES'   => true,
        'GLOBALS'  => true,
    ];

    private const KEYWORD_CONSTANTS = [
        'true'  => true,
        'false' => true,
        'null'  => true,
    ];

    /**
     * A string value shaped like a (possibly namespaced) class name, e.g.
     * 'App\Contract' or 'stdClass'. Such values can reach `new $class` or
     * `instanceof $class` at runtime, so they count as references.
     */
    private const CLASS_LIKE_STRING_PATTERN =
        '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*+(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*+)*+$/';

    /**
     * Method names of the ReflectionClass object-construction APIs. Calling
     * one chained on a `new ReflectionClass(<resolvable class name>)` receiver
     * instantiates the reflected class.
     */
    private const REFLECTION_CONSTRUCTION_METHODS = [
        'newinstance'                   => true,
        'newinstanceargs'               => true,
        'newinstancewithoutconstructor' => true,
        'newlazyghost'                  => true,
        'newlazyproxy'                  => true,
    ];

    /**
     * Node classes counted as cyclomatic-complexity branches. The parser only
     * ever instantiates these exact classes, so a single ::class hash lookup
     * replaces an instanceof chain on the per-node hot path.
     */
    private const COMPLEXITY_BRANCH_NODES = [
        If_::class                   => true,
        ElseIf_::class               => true,
        For_::class                  => true,
        Foreach_::class              => true,
        While_::class                => true,
        Do_::class                   => true,
        Case_::class                 => true,
        Catch_::class                => true,
        Ternary::class               => true,
        BooleanAnd::class            => true,
        BooleanOr::class             => true,
        LogicalAnd::class            => true,
        LogicalOr::class             => true,
        Coalesce::class              => true,
        AssignCoalesce::class        => true,
        NullsafeMethodCall::class    => true,
        NullsafePropertyFetch::class => true,
        MatchArm::class              => true,
    ];

    /**
     * Node classes that map to a fixed language-construct name. Exit_ and
     * Include_ are handled separately: their names depend on node data.
     */
    private const LANGUAGE_CONSTRUCT_NODES = [
        Echo_::class  => 'echo',
        Print_::class => 'print',
        Isset_::class => 'isset',
        Empty_::class => 'empty',
        Unset_::class => 'unset',
        Eval_::class  => 'eval',
        List_::class  => 'list',
    ];

    /** @var list<ClassNode> */
    private array $nodes = [];

    /** @var list<AnonymousClassNode> */
    private array $anonymousClassNodes = [];

    /** @var array<string, list<string>> */
    private array $fileReferences = [];

    /** @var list<string> */
    private array $currentFileReferences = [];

    /**
     * Separator of a deferred instantiation marker, `<keyword>@<class-like>`,
     * recorded when `new self()`, `new static()`, or `new parent()` cannot be
     * resolved to a class name until every class has been collected. The `@`
     * cannot occur in a class name, so a marker never collides with a real
     * instantiation target.
     *
     * @see deferredInstantiationMarker()
     * @see parseDeferredInstantiationMarker()
     */
    private const DEFERRED_MARKER_SEPARATOR = '@';

    /** @var array<string, list<string>> */
    private array $fileInstantiations = [];

    /** @var list<string> */
    private array $currentFileInstantiations = [];

    private readonly ConstExprEvaluator $constExprEvaluator;

    /**
     * Stack of class-likes currently being entered, so `new self`,
     * `new static`, and `new parent` instantiations can be resolved to the
     * class names (or deferred markers) they target. Anonymous classes have
     * no name to resolve self/static to, but their `extends` still resolves
     * `parent`.
     *
     * @var list<array{self: string|null, static: string|null, parent: string|null}>
     */
    private array $activeClassLikeScopes = [];

    private string $currentFile = '';

    /** @var list<string> */
    private array $currentNamespaceUses = [];

    /** @var ClassLike[] */
    private array $fileClassLikes = [];

    /** @var array<string, true> */
    private array $fileFunctions = [];

    /** @var array<int, ClassLikeAnalysis> */
    private array $classLikeAnalysis = [];

    /** @var list<ClassLikeAnalysis> */
    private array $activeClassLikeAnalyses = [];

    /** @var list<int> */
    private array $activeMethodIds = [];

    /** @var array<int, ClassLikeAnalysis> */
    private array $methodClassLikeAnalyses = [];

    public function __construct(
        private readonly LayerResolverInterface $layerResolver
    ) {
        $this->constExprEvaluator = new ConstExprEvaluator(function (Expr $expr): string {
            if (
                $expr instanceof ClassConstFetch
                && $expr->name instanceof Identifier
                && $expr->name->toLowerString() === 'class'
                && $expr->class instanceof Name
            ) {
                $className = $this->resolveClassLikeName($expr->class);

                if ($className !== null) {
                    return $className;
                }
            }

            throw new ConstExprEvaluationException('Expression is not a resolvable class name.');
        });
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile               = $file;
        $this->currentFileReferences     = [];
        $this->currentFileInstantiations = [];
        $this->currentNamespaceUses      = [];
        $this->fileClassLikes            = [];
        $this->fileFunctions             = [];
        $this->classLikeAnalysis         = [];
        $this->activeClassLikeAnalyses   = [];
        $this->activeMethodIds           = [];
        $this->methodClassLikeAnalyses   = [];
    }

    /** @return list<ClassNode> */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /** @return list<AnonymousClassNode> */
    public function getAnonymousClassNodes(): array
    {
        return $this->anonymousClassNodes;
    }

    /**
     * References to class-likes made outside any named class-like scope, per
     * file — procedural functions, top-level statements, and top-level
     * anonymous class bodies.
     *
     * @return array<string, list<string>>
     */
    public function getFileReferences(): array
    {
        return $this->fileReferences;
    }

    /**
     * Class-like instantiations (`new X`, with self/static/parent resolved to
     * the class names they target), per file. `new` on an abstract class is
     * fatal, so these are what an extended class needs to stay concrete.
     *
     * `new parent()` inside a trait is recorded as a marker instead, see
     * {@see traitFromParentMarker()}.
     *
     * @return array<string, list<string>>
     */
    public function getFileInstantiations(): array
    {
        return $this->fileInstantiations;
    }

    /**
     * Marker recorded in place of a class name for `new <keyword>()` whose
     * target depends on classes not yet collected: `self`, `static`, and
     * `parent` inside a trait resolve against each class using the trait,
     * and `static` inside a class also covers its descendants.
     *
     * @param 'self'|'static'|'parent' $keyword
     */
    public static function deferredInstantiationMarker(string $keyword, string $classLikeName): string
    {
        return $keyword . self::DEFERRED_MARKER_SEPARATOR . $classLikeName;
    }

    /**
     * The keyword and class-like name carried by a deferred instantiation
     * marker, or null when the instantiation is a plain class name.
     *
     * @return array{0: 'self'|'static'|'parent', 1: string}|null
     */
    public static function parseDeferredInstantiationMarker(string $instantiation): ?array
    {
        $separatorPosition = strpos($instantiation, self::DEFERRED_MARKER_SEPARATOR);

        if ($separatorPosition === false) {
            return null;
        }

        $keyword = substr($instantiation, 0, $separatorPosition);

        if (! in_array($keyword, ['self', 'static', 'parent'], true)) {
            return null;
        }

        return [$keyword, substr($instantiation, $separatorPosition + 1)];
    }

    public function enterNode(Node $node): null
    {
        // The scope-tracking node types are all statements, so the far more
        // frequent expression/name/identifier nodes skip their checks with a
        // single instanceof.
        if ($node instanceof Stmt) {
            if ($node instanceof Namespace_) {
                $this->currentNamespaceUses = [];

                return null;
            }

            if ($node instanceof Use_) {
                foreach ($node->uses as $use) {
                    $this->currentNamespaceUses[] = $use->name->toString();
                }

                return null;
            }

            if ($node instanceof GroupUse) {
                $prefix = $node->prefix->toString();

                foreach ($node->uses as $use) {
                    $this->currentNamespaceUses[] = $prefix . '\\' . $use->name->toString();
                }

                return null;
            }

            if ($node instanceof Function_) {
                if (isset($node->namespacedName)) {
                    $this->fileFunctions[$node->namespacedName->toString()] = true;
                }

                return null;
            }

            if ($node instanceof ClassLike) {
                $this->activeClassLikeScopes[] = $this->createClassLikeScope($node);

                if ($node->name instanceof Identifier) {
                    $this->startClassLikeAnalysis($node);
                }

                return null;
            }

            if ($node instanceof ClassMethod) {
                $this->startMethodAnalysis($node);

                return null;
            }
        }

        $this->collectNodeAnalysis($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Both instantiation handlers run on leave, once the NameResolver
        // has resolved the nested name nodes (e.g. Base::class inside the
        // class expression). They only match expressions, and ClassMethod /
        // ClassLike are statements, so one instanceof splits the two groups.
        if ($node instanceof Expr) {
            // Instantiations are tracked separately from plain references:
            // `new` on an abstract class is fatal, so instantiation is the one
            // usage that requires an extended class to stay concrete — type
            // hints, instanceof checks, and ::class constants all keep working
            // once a class becomes abstract.
            if ($node instanceof New_) {
                $this->collectInstantiation($node);

                return null;
            }

            // A ReflectionClass construction call instantiates the reflected
            // class when the reflection target is statically resolvable. The
            // `new` receiver is checked first: it is the rare shape, so the
            // common method call skips the name lowering entirely.
            if (
                ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                && $node->var instanceof New_
                && $node->name instanceof Identifier
                && isset(self::REFLECTION_CONSTRUCTION_METHODS[$node->name->toLowerString()])
            ) {
                $this->collectReflectionInstantiation($node->var);
            }

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->finishMethodAnalysis($node);

            return null;
        }

        if (! $node instanceof ClassLike) {
            return null;
        }

        array_pop($this->activeClassLikeScopes);

        if (! $node->name instanceof Identifier) {
            // Anonymous classes never become ClassNodes, but the class they
            // extend, the interfaces they implement, and the traits they use
            // are still used within the scanned paths.
            if ($node instanceof Class_) {
                $this->anonymousClassNodes[] = new AnonymousClassNode(
                    file:       $this->currentFile,
                    line:       $node->getStartLine(),
                    extends:    $node->extends instanceof Name ? $node->extends->toString() : null,
                    implements: $this->collectImplements($node),
                    traits:     $this->collectTraits($node),
                );
            }

            return null;
        }

        $this->fileClassLikes[] = $node;
        array_pop($this->activeClassLikeAnalyses);

        return null;
    }

    /** @param Node[] $nodes */
    public function afterTraverse(array $nodes): null
    {
        foreach ($this->fileClassLikes as $fileClassLike) {
            $this->collectClassLike($fileClassLike);
        }

        if ($this->currentFileReferences !== []) {
            $this->fileReferences[$this->currentFile] = array_values(array_unique($this->currentFileReferences));
            $this->currentFileReferences              = [];
        }

        if ($this->currentFileInstantiations !== []) {
            $this->fileInstantiations[$this->currentFile] = array_values(
                array_unique($this->currentFileInstantiations)
            );
            $this->currentFileInstantiations              = [];
        }

        $this->fileClassLikes          = [];
        $this->classLikeAnalysis       = [];
        $this->activeClassLikeAnalyses = [];
        $this->activeClassLikeScopes   = [];
        $this->activeMethodIds         = [];
        $this->methodClassLikeAnalyses = [];

        return null;
    }

    private function startClassLikeAnalysis(ClassLike $classLike): void
    {
        $classLikeId       = spl_object_id($classLike);
        $classLikeAnalysis = new ClassLikeAnalysis();

        $classLikeAnalysis->dependencies = $this->currentNamespaceUses;

        $this->classLikeAnalysis[$classLikeId] = $classLikeAnalysis;
        $this->activeClassLikeAnalyses[]       = $classLikeAnalysis;

        foreach ($classLike->getMethods() as $classMethod) {
            $this->methodClassLikeAnalyses[spl_object_id($classMethod)] = $classLikeAnalysis;
        }
    }

    private function startMethodAnalysis(ClassMethod $classMethod): void
    {
        $methodId = spl_object_id($classMethod);

        $analysis = $this->methodClassLikeAnalyses[$methodId] ?? null;

        if (! $analysis instanceof ClassLikeAnalysis) {
            return;
        }

        $this->activeMethodIds[] = $methodId;

        $analysis->complexityByMethodId[$methodId] = 1;
    }

    private function finishMethodAnalysis(ClassMethod $classMethod): void
    {
        if (! isset($this->methodClassLikeAnalyses[spl_object_id($classMethod)])) {
            return;
        }

        array_pop($this->activeMethodIds);
    }

    private function collectNodeAnalysis(Node $node): void
    {
        // A class-name-shaped string literal may feed `new $class`,
        // `$obj instanceof $class`, class_exists(), container ids, and so on.
        // Whether it appears inside a class-like or in procedural code, treat
        // it as a file-level reference so the named class-like stays alive.
        if ($node instanceof String_) {
            // A leading `\` is a valid fully-qualified spelling
            // (`'\App\Contract'`); strip it so the stored name matches the
            // ClassNode::$className form used for usage lookups.
            $value = $this->stripLeadingNamespaceSeparator($node->value);

            if (
                preg_match(self::CLASS_LIKE_STRING_PATTERN, $value) === 1
                && ! isset(self::KEYWORD_CONSTANTS[strtolower($value)])
            ) {
                $this->currentFileReferences[] = $value;
            }

            return;
        }

        if ($node instanceof FullyQualified) {
            $name = $node->toString();

            if (isset(self::KEYWORD_CONSTANTS[strtolower($name)])) {
                return;
            }

            if ($this->activeClassLikeAnalyses === []) {
                // Outside any named class-like scope — procedural functions,
                // top-level statements, top-level anonymous class bodies — a
                // class-like reference still keeps the referenced class-like
                // alive.
                $this->currentFileReferences[] = $name;

                return;
            }

            $this->addDependency($name);

            return;
        }

        if ($this->activeClassLikeAnalyses === []) {
            return;
        }

        // Branch nodes (conditions, loops, boolean operators) are among the
        // most frequent remaining node types, so they dispatch on one hash
        // lookup before the rarer per-type checks below.
        if (isset(self::COMPLEXITY_BRANCH_NODES[$node::class])) {
            foreach ($this->activeMethodIds as $activeMethodId) {
                $this->methodClassLikeAnalyses[$activeMethodId]->complexityByMethodId[$activeMethodId]++;
            }

            return;
        }

        if ($node instanceof Variable) {
            if (is_string($node->name) && isset(self::SUPERGLOBALS[$node->name])) {
                $this->addSuperglobal('$' . $node->name);
            }

            return;
        }

        if ($node instanceof FuncCall) {
            if ($node->name instanceof Name) {
                $functionName = $node->name->toLowerString();

                // PHP 8.4 generalized exit/die (e.g. named arguments) parse as
                // FuncCall instead of Exit_, but remain language constructs
                if ($functionName === 'exit' || $functionName === 'die') {
                    $this->addLanguageConstruct($functionName);
                } else {
                    $this->addFunctionCallName($node->name);
                }
            }

            return;
        }

        if ($node instanceof Exit_) {
            $this->addLanguageConstruct(
                $node->getAttribute('kind') === Exit_::KIND_DIE
                    ? 'die'
                    : 'exit'
            );

            return;
        }

        if ($node instanceof Include_) {
            $this->addLanguageConstruct(match ($node->type) {
                Include_::TYPE_REQUIRE      => 'require',
                Include_::TYPE_INCLUDE_ONCE => 'include_once',
                Include_::TYPE_REQUIRE_ONCE => 'require_once',
                default                     => 'include',
            });

            return;
        }

        $languageConstruct = self::LANGUAGE_CONSTRUCT_NODES[$node::class] ?? null;

        if ($languageConstruct !== null) {
            $this->addLanguageConstruct($languageConstruct);
        }
    }

    private function collectInstantiation(New_ $new): void
    {
        $class = $new->class;

        if ($class instanceof Name) {
            $className = $this->resolveClassLikeName($class);

            if ($className !== null) {
                $this->currentFileInstantiations[] = $className;
            }

            return;
        }

        // Anonymous classes (`new class {}`) are tracked as
        // AnonymousClassNodes; constant class expressions may still resolve
        // below. Runtime-fed dynamic instantiations (`new \$class` from a
        // parameter, unserialize(), containers) are part of the documented
        // scanned-code boundary and resolve to nothing.
        if (! $class instanceof Expr) {
            return;
        }

        // `new (X::class)` / `new ('App\X')` constant class expressions.
        $className = $this->resolveClassNameExpr($class);

        if ($className !== null) {
            $this->currentFileInstantiations[] = $className;
        }
    }

    /**
     * Resolve a class-like name node to a fully qualified name (or deferred
     * marker): either it is already fully qualified, or it is a
     * self/static/parent keyword resolved against the enclosing class-like
     * scope. Returns null when there is no scope to resolve against.
     */
    private function resolveClassLikeName(Name $name): ?string
    {
        if ($name instanceof FullyQualified) {
            return $name->toString();
        }

        // After name resolution only self, static, and parent survive as
        // plain names.
        $scope = end($this->activeClassLikeScopes);

        if ($scope === false) {
            return null;
        }

        return $scope[$name->toLowerString()] ?? null;
    }

    /**
     * A trait is never instantiated itself: `self`, `static`, and `parent`
     * target whichever class uses the trait, and `static` in a class also
     * targets its descendants. Both are only known once every class has been
     * collected, so those scope entries carry a marker the analyser resolves
     * later, in place of a class name.
     *
     * @return array{self: string|null, static: string|null, parent: string|null}
     */
    private function createClassLikeScope(ClassLike $classLike): array
    {
        $parent = $classLike instanceof Class_ && $classLike->extends instanceof Name
            ? $classLike->extends->toString()
            : null;

        if (! $classLike->name instanceof Identifier) {
            return ['self' => null, 'static' => null, 'parent' => $parent];
        }

        $name = $this->resolveClassName($classLike);

        if ($classLike instanceof Trait_) {
            return [
                'self'   => self::deferredInstantiationMarker('self', $name),
                'static' => self::deferredInstantiationMarker('static', $name),
                'parent' => self::deferredInstantiationMarker('parent', $name),
            ];
        }

        return [
            'self'   => $name,
            'static' => self::deferredInstantiationMarker('static', $name),
            'parent' => $parent,
        ];
    }

    /**
     * Resolve `new ReflectionClass(<constant class-name expression>)` to the
     * reflected class name, or null for anything else.
     */
    private function resolveReflectionTarget(New_ $new): ?string
    {
        if (! $new->class instanceof Name) {
            return null;
        }

        if (strcasecmp($new->class->toString(), 'ReflectionClass') !== 0) {
            return null;
        }

        $firstArg = $new->args[0] ?? null;

        if (! $firstArg instanceof Arg) {
            return null;
        }

        return $this->resolveClassNameExpr($firstArg->value);
    }

    /**
     * Record the reflected class as instantiated when a construction method is
     * called chained on a `new` receiver that is a resolvable ReflectionClass.
     * Anything else — variable-held reflections, runtime-named targets —
     * records nothing: that is part of the documented scanned-code boundary.
     */
    private function collectReflectionInstantiation(New_ $new): void
    {
        $reflectionTarget = $this->resolveReflectionTarget($new);

        if ($reflectionTarget !== null) {
            $this->currentFileInstantiations[] = $reflectionTarget;
        }
    }

    /**
     * Evaluate a constant expression to a class-name string: 'App\X' literals,
     * X::class (including self/static/parent::class, which may yield a
     * deferred marker), and concatenations of those. Anything depending on
     * runtime values resolves to null.
     */
    private function resolveClassNameExpr(Expr $expr): ?string
    {
        if (! $expr instanceof String_ && ! $expr instanceof ClassConstFetch && ! $expr instanceof Concat) {
            return null;
        }

        try {
            /** @var string $value */
            $value = $this->constExprEvaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }

        $marker = self::parseDeferredInstantiationMarker($value);

        if ($marker === null) {
            $value = $this->stripLeadingNamespaceSeparator($value);
        }

        return preg_match(self::CLASS_LIKE_STRING_PATTERN, $marker[1] ?? $value) === 1
            ? $value
            : null;
    }

    /**
     * Statically resolve a backed enum case value. Returns null for a pure
     * enum case and for values that depend on symbols outside the expression
     * (global constants, other class constants), which the analyser cannot
     * evaluate.
     */
    private function resolveEnumCaseValue(?Expr $expr): int|string|null
    {
        if (! $expr instanceof Expr) {
            return null;
        }

        try {
            $value = $this->constExprEvaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * `'\App\X'` and `'App\X'` name the same class; the collector stores the
     * latter form so usage keys line up with ClassNode::$className.
     */
    private function stripLeadingNamespaceSeparator(string $name): string
    {
        return str_starts_with($name, '\\') ? substr($name, 1) : $name;
    }

    private function addDependency(string $dependency): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->dependencies[] = $dependency;
        }
    }

    private function addFunctionCallName(Name $functionCallName): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->functionCallNames[] = $functionCallName;
        }
    }

    private function addSuperglobal(string $superglobal): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->superglobals[] = $superglobal;
        }
    }

    private function addLanguageConstruct(string $languageConstruct): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->languageConstructs[] = $languageConstruct;
        }
    }

    private function collectClassLike(ClassLike $classLike): void
    {
        $classLikeId      = spl_object_id($classLike);
        $analysis         = $this->collectClassLikeAnalysis($classLikeId);
        $className        = $this->resolveClassName($classLike);
        $layers           = $this->layerResolver->resolveAll($className, $this->currentFile);
        $layer            = $this->layerResolver->resolve($className, $this->currentFile);
        $implements       = $this->collectImplements($classLike);
        $interfaceExtends = $this->collectInterfaceExtends($classLike);

        [$traits, $constants, $properties, $methods, $enumCases] = $this->collectMembers(
            $classLike,
            $analysis['complexityByMethodId']
        );

        $this->nodes[] = new ClassNode(
            className:          $className,
            file:               $this->currentFile,
            line:               $classLike->getStartLine(),
            layer:              $layer,
            extends:            $classLike instanceof Class_ && $classLike->extends instanceof Name
                                    ? $classLike->extends->toString()
                                    : null,
            isAbstract:         $classLike instanceof Class_ && $classLike->isAbstract(),
            isFinal:            $classLike instanceof Class_ && $classLike->isFinal(),
            isInterface:        $classLike instanceof Interface_,
            isReadonly:         $classLike instanceof Class_ && $classLike->isReadonly(),
            isTrait:            $classLike instanceof Trait_,
            dependencies:       $analysis['dependencies'],
            implements:         $implements,
            traits:             $traits,
            methods:            $methods,
            constants:          $constants,
            properties:         $properties,
            functionCalls:      $analysis['functionCalls'],
            superglobals:       $analysis['superglobals'],
            languageConstructs: $analysis['languageConstructs'],
            layers:             $layers,
            isEnum:             $classLike instanceof Enum_,
            interfaceExtends:   $interfaceExtends,
            enumCases:          $enumCases,
            enumBackingType:    $classLike instanceof Enum_ && $classLike->scalarType instanceof Identifier
                                    ? $classLike->scalarType->toLowerString()
                                    : null,
        );
    }

    /**
     * Collect traits, constants, properties, methods, and enum cases in a single
     * pass over the class-like statements instead of one loop per member kind.
     *
     * @param array<int, int> $complexityByMethodId
     * @return array{0: string[], 1: ConstantNode[], 2: PropertyNode[], 3: MethodNode[], 4: EnumCaseNode[]}
     */
    private function collectMembers(ClassLike $classLike, array $complexityByMethodId): array
    {
        $isInterface = $classLike instanceof Interface_;
        $traits      = [];
        $constants   = [];
        $properties  = [];
        $methods     = [];
        $enumCases   = [];

        foreach ($classLike->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                if ($isInterface) {
                    continue;
                }

                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->toString();
                }

                continue;
            }

            if ($stmt instanceof ClassConst) {
                $visibility            = $this->resolveVisibilityName($stmt);
                $hasExplicitVisibility = VisibilityFlagChecker::hasExplicitVisibilityFlag($stmt->flags);

                foreach ($stmt->consts as $const) {
                    $constants[] = new ConstantNode(
                        name:                 (string) $const->name,
                        visibility:           $visibility,
                        hasExplicitVisibility: $hasExplicitVisibility,
                        line:                 $const->getStartLine(),
                    );
                }

                continue;
            }

            if ($stmt instanceof Property) {
                $visibility            = $this->resolveVisibilityName($stmt);
                $hasExplicitVisibility = VisibilityFlagChecker::hasExplicitVisibilityFlag($stmt->flags);

                foreach ($stmt->props as $prop) {
                    $properties[] = new PropertyNode(
                        name:                 (string) $prop->name,
                        visibility:           $visibility,
                        hasExplicitVisibility: $hasExplicitVisibility,
                        line:                 $prop->getStartLine(),
                    );
                }

                continue;
            }

            if ($stmt instanceof EnumCase) {
                $enumCases[] = new EnumCaseNode(
                    name:  (string) $stmt->name,
                    line:  $stmt->getStartLine(),
                    value: $this->resolveEnumCaseValue($stmt->expr),
                );

                continue;
            }

            if ($stmt instanceof ClassMethod) {
                $methods[] = new MethodNode(
                    name:                 (string) $stmt->name,
                    visibility:           $this->resolveVisibilityName($stmt),
                    hasReturnType:        $stmt->returnType instanceof Node,
                    isStatic:             $stmt->isStatic(),
                    paramCount:           count($stmt->params),
                    cyclomaticComplexity: $complexityByMethodId[spl_object_id($stmt)] ?? 1,
                    lineCount:            $this->calculateMethodLineCount($stmt),
                    hasExplicitVisibility: VisibilityFlagChecker::hasExplicitVisibilityFlag($stmt->flags),
                    line:                 $stmt->getStartLine(),
                    isMagic:              $stmt->isMagic(),
                );

                if ($stmt->name->toLowerString() !== '__construct') {
                    continue;
                }

                foreach ($stmt->params as $param) {
                    if (
                        ! $param->isPromoted()
                        || ! $param->var instanceof Variable
                        || ! is_string($param->var->name)
                    ) {
                        continue;
                    }

                    $properties[] = new PropertyNode(
                        name:                  (string) $param->var->name,
                        visibility:            $this->resolveVisibilityName($param),
                        hasExplicitVisibility: VisibilityFlagChecker::hasExplicitVisibilityFlag($param->flags),
                        line:                  $param->getStartLine(),
                    );
                }
            }
        }

        return [$traits, $constants, $properties, $methods, $enumCases];
    }

    private function resolveClassName(ClassLike $classLike): string
    {
        return isset($classLike->namespacedName)
            ? $classLike->namespacedName->toString()
            : (string) $classLike->name;
    }

    /**
     * @return array{
     *     dependencies: list<string>,
     *     functionCalls: string[],
     *     superglobals: string[],
     *     languageConstructs: string[],
     *     complexityByMethodId: array<int, int>
     * }
     */
    private function collectClassLikeAnalysis(int $classLikeId): array
    {
        $analysis      = $this->classLikeAnalysis[$classLikeId] ?? new ClassLikeAnalysis();
        $functionCalls = [];

        foreach ($analysis->functionCallNames as $functionCallName) {
            $functionCalls[] = $this->resolveFunctionName($functionCallName);
        }

        return [
            'dependencies'         => array_values(array_unique($analysis->dependencies)),
            'functionCalls'        => array_values(array_unique($functionCalls)),
            'superglobals'         => array_values(array_unique($analysis->superglobals)),
            'languageConstructs'   => array_values(array_unique($analysis->languageConstructs)),
            'complexityByMethodId' => $analysis->complexityByMethodId,
        ];
    }

    private function resolveFunctionName(Name $name): string
    {
        $functionName = $name->toString();

        if ($name instanceof FullyQualified) {
            return $functionName;
        }

        $namespacedName = $name->getAttribute('namespacedName');

        if ($namespacedName instanceof Name) {
            $namespacedNameString = $namespacedName->toString();

            if (isset($this->fileFunctions[$namespacedNameString])) {
                return $namespacedNameString;
            }
        }

        return $functionName;
    }

    /**
     * @return string[]
     */
    private function collectImplements(ClassLike $classLike): array
    {
        $interfaces = [];

        if ($classLike instanceof Class_ || $classLike instanceof Enum_) {
            foreach ($classLike->implements as $interface) {
                $interfaces[] = $interface->toString();
            }
        }

        return $interfaces;
    }

    /**
     * @return string[]
     */
    private function collectInterfaceExtends(ClassLike $classLike): array
    {
        if (! $classLike instanceof Interface_) {
            return [];
        }

        $parents = [];

        foreach ($classLike->extends as $parent) {
            $parents[] = $parent->toString();
        }

        return $parents;
    }

    /**
     * Traits used by an anonymous class; named class-likes collect theirs in
     * collectMembers().
     *
     * @return string[]
     */
    private function collectTraits(Class_ $class): array
    {
        $traits = [];

        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        return $traits;
    }

    private function resolveVisibilityName(ClassMethod|ClassConst|Property|Param $node): string
    {
        if ($node->isProtected()) {
            return 'protected';
        }

        if ($node->isPrivate()) {
            return 'private';
        }

        return 'public';
    }

    private function calculateMethodLineCount(ClassMethod $classMethod): int
    {
        if ($classMethod->stmts === null || $classMethod->stmts === []) {
            return 0;
        }

        $lastIndex = count($classMethod->stmts) - 1;
        return $classMethod->stmts[$lastIndex]->getEndLine() - $classMethod->stmts[0]->getStartLine() + 1;
    }
}
