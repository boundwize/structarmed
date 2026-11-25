<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Analyser\CollectorState\ClassLikeAnalysis;
use Boundwize\StructArmed\Analyser\CollectorState\FileReferenceAnalysis;
use Boundwize\StructArmed\Analyser\CollectorState\FileSyntaxAnalysis;
use Boundwize\StructArmed\Analyser\CollectorState\FunctionLikeAnalysis;
use Boundwize\StructArmed\Analyser\CollectorState\MethodAnalysis;
use Boundwize\StructArmed\Analyser\CollectorState\NamespaceAnalysis;
use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\PhpParser\AnonymousClassParentheses;
use Boundwize\StructArmed\Util\PhpParser\EmptyParentheses;
use Boundwize\StructArmed\Util\PhpParser\EnumCaseLine;
use Boundwize\StructArmed\Util\PhpParser\ObjectBoundAnonymousFunction;
use Boundwize\StructArmed\Util\PhpParser\UnconditionallyDeclaredFunctions;
use Boundwize\StructArmed\Util\PhpParser\VisibilityFlagChecker;
use PhpParser\Comment\Doc;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
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
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\Namespace_ as NamespaceMagicConst;
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
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

use function array_change_key_case;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_push;
use function count;
use function end;
use function in_array;
use function is_finite;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * Collects every analysis node from a file's AST: ClassNodes (with their
 * anonymous-class, file-reference, and instantiation side data), FunctionNodes,
 * and AnonymousFunctionNodes.
 *
 * @internal
 */
final class AnalysisNodeCollector extends NodeVisitorAbstract
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
        '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*+(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*+)*+\z/';

    /**
     * The method part of a static callable string, e.g. 'run' in
     * 'App\Handler::run'.
     */
    private const METHOD_NAME_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*+\z/';

    /**
     * A docblock tag at the start of a line, e.g. `@api`; %s is the tag name.
     */
    private const DOC_BLOCK_TAG_PATTERN = '#^[/*\s]*@%s(?![\w-])#m';

    /**
     * Marks the name string passed to function_exists(): the check probes for
     * a function, it does not use it, so the string is not a reference.
     */
    private const FUNCTION_EXISTS_CHECK_ATTRIBUTE = 'structarmedFunctionExistsCheck';

    /**
     * Marks a concatenation that is an operand of another one: the outer
     * concatenation collects the value, see collectConcatReference().
     */
    private const CONCAT_OPERAND_ATTRIBUTE = 'structarmedConcatOperand';

    /** The value a concatenation operand resolved to, read by the outer concatenation. */
    private const CONCAT_VALUE_ATTRIBUTE = 'structarmedConcatValue';

    /**
     * Marks the fully-qualified name of a function call or constant fetch: it
     * is still a dependency, but not a use of a class of that name.
     */
    private const NON_CLASS_NAME_ATTRIBUTE = 'structarmedNonClassName';

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

    /**
     * Class-like member statements collected on enter, see collectMember().
     * Enum cases are collected on leaving the enum instead, see
     * collectEnumCases().
     */
    private const MEMBER_NODES = [
        Property::class   => true,
        ClassConst::class => true,
        TraitUse::class   => true,
    ];

    /**
     * Every node class enterNode() acts on: the scope-tracking statements,
     * function-likes, and everything collectNodeAnalysis() records. The
     * parser only ever instantiates these exact classes, so a single ::class
     * hash lookup lets the large majority of nodes (identifiers, arguments,
     * most scalars, assignments, ...) return before any instanceof check.
     */
    private const ENTER_NODES = self::COMPLEXITY_BRANCH_NODES
        + self::LANGUAGE_CONSTRUCT_NODES
        + self::MEMBER_NODES
        + [
            Namespace_::class     => true,
            Use_::class           => true,
            GroupUse::class       => true,
            Function_::class      => true,
            Class_::class         => true,
            Interface_::class     => true,
            Trait_::class         => true,
            Enum_::class          => true,
            ClassMethod::class    => true,
            Closure::class        => true,
            ArrowFunction::class  => true,
            String_::class        => true,
            Concat::class         => true,
            Int_::class           => true,
            Float_::class         => true,
            FullyQualified::class => true,
            ConstFetch::class     => true,
            Variable::class       => true,
            FuncCall::class       => true,
            Exit_::class          => true,
            ShellExec::class      => true,
            Include_::class       => true,
            Attribute::class      => true,
        ];

    /**
     * Every node class leaveNode() acts on, see ENTER_NODES.
     */
    private const LEAVE_NODES = [
        Closure::class            => true,
        ArrowFunction::class      => true,
        New_::class               => true,
        Concat::class             => true,
        StaticCall::class         => true,
        MethodCall::class         => true,
        NullsafeMethodCall::class => true,
        ClassMethod::class        => true,
        Property::class           => true,
        Function_::class          => true,
        Class_::class             => true,
        Interface_::class         => true,
        Trait_::class             => true,
        Enum_::class              => true,
    ];

    /** @var list<ClassNode> */
    private array $classNodes = [];

    /** @var list<AnonymousClassNode> */
    private array $anonymousClassNodes = [];

    /** @var list<FunctionNode> */
    private array $functionNodes = [];

    /** @var list<AnonymousFunctionNode> */
    private array $anonymousFunctionNodes = [];

    /** @var array<string, list<string>> */
    private array $fileReferences = [];

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

    /**
     * Prefix of a function fallback marker, `?<namespaced function>`, recorded
     * as a file reference and a node's function call for an unqualified call
     * in a namespace whose function is not declared in the same file. PHP
     * calls the namespaced function when it exists and the global one
     * otherwise, which is only known once every function has been collected.
     * The `?` cannot occur in a name, so a marker never collides with a real
     * reference.
     *
     * @see parseFunctionFallbackMarker()
     */
    private const FUNCTION_FALLBACK_MARKER_PREFIX = '?';

    /** Resolved function references cannot collide with class-like names. */
    private const FUNCTION_REFERENCE_PREFIX = '!';

    /** @var array<string, list<string>> */
    private array $fileInstantiations = [];

    private readonly ConstExprEvaluator $constExprEvaluator;

    private NamespaceAnalysis $namespaceAnalysis;

    private FileReferenceAnalysis $fileReferenceAnalysis;

    private FileSyntaxAnalysis $fileSyntaxAnalysis;

    /**
     * Each class-like left in the current file with the facts collected
     * while traversing it and, for an anonymous class, the named scopes
     * declaring it — innermost class-like name, innermost function name —
     * read once its node is built.
     *
     * @var list<array{ClassLike, ClassLikeAnalysis, string|null, string|null}>
     */
    private array $fileClassLikes = [];

    /** @var list<ClassLikeAnalysis> */
    private array $activeClassLikeAnalyses = [];

    /** @var list<MethodAnalysis> Innermost method last */
    private array $activeMethodAnalyses = [];

    /** @var list<FunctionLikeAnalysis> */
    private array $activeFunctionLikeAnalyses = [];

    /**
     * Every function-like entered in the current file, in source order. Their
     * nodes are built in afterTraverse(), once every function declared in the
     * file is known and unqualified function calls can be resolved.
     *
     * @var list<FunctionLikeAnalysis>
     */
    private array $fileFunctionLikeAnalyses = [];

    /**
     * @param bool $withFileAnalysis Whether to record the facts only file-analysis
     *                               rules read ({@see getNonCanonicalKeywordConstants()},
     *                               {@see getNumericLiterals()},
     *                               {@see getAttributesWithEmptyParentheses()}); an
     *                               architecture-only run never reads them
     * @param bool $withFileReferences Whether to keep the per-file references and
     *                                 instantiations ({@see getFileReferences()},
     *                                 {@see getFileInstantiations()}); only the rules
     *                                 that track class-like or function usage read them
     */
    public function __construct(
        private readonly LayerResolverInterface $layerResolver,
        private readonly bool $withFileAnalysis = true,
        private readonly bool $withSignatures = false,
        private readonly bool $withFileReferences = true,
    ) {
        $this->constExprEvaluator = new ConstExprEvaluator($this->evaluateClassNameFetch(...));
        $this->initGroupedAnalysis();
    }

    /** @param array<Token> $tokens The file's token stream, for the facts its AST does not carry */
    public function setCurrentFile(string $file, array $tokens = []): void
    {
        $this->initGroupedAnalysis($file, $tokens);

        $this->fileClassLikes             = [];
        $this->activeClassLikeAnalyses    = [];
        $this->activeMethodAnalyses       = [];
        $this->activeFunctionLikeAnalyses = [];
        $this->fileFunctionLikeAnalyses   = [];
    }

    /** @param array<Token> $tokens */
    private function initGroupedAnalysis(string $file = '', array $tokens = []): void
    {
        $this->namespaceAnalysis     = new NamespaceAnalysis();
        $this->fileReferenceAnalysis = new FileReferenceAnalysis();
        $this->fileSyntaxAnalysis    = new FileSyntaxAnalysis($file, $tokens);
    }

    /** @return list<ClassNode> */
    public function getClassNodes(): array
    {
        return $this->classNodes;
    }

    /** @return list<FunctionNode> */
    public function getFunctionNodes(): array
    {
        return $this->functionNodes;
    }

    /** @return list<AnonymousFunctionNode> */
    public function getAnonymousFunctionNodes(): array
    {
        return $this->anonymousFunctionNodes;
    }

    /** @return list<AnonymousClassNode> */
    public function getAnonymousClassNodes(): array
    {
        return $this->anonymousClassNodes;
    }

    /**
     * References to class-likes made outside any class-like scope, per file —
     * procedural functions and top-level statements. Function references use
     * resolved or fallback markers so they cannot keep class-likes alive.
     *
     * @return array<string, list<string>>
     */
    public function getFileReferences(): array
    {
        return $this->fileReferences;
    }

    /**
     * Keyword constants of the file traversed last that are not spelled in
     * lowercase, see MustUseLowercaseKeywordConstantRule.
     *
     * @return list<array{int, string}>
     */
    public function getNonCanonicalKeywordConstants(): array
    {
        return $this->fileSyntaxAnalysis->nonCanonicalKeywordConstants;
    }

    /**
     * Numeric literals of the file traversed last, as [line, spelling as
     * written, evaluated value].
     *
     * @return list<array{int, string, int|float}>
     */
    public function getNumericLiterals(): array
    {
        return $this->fileSyntaxAnalysis->numericLiterals;
    }

    /**
     * Attributes of the file traversed last that spell `()` although they pass
     * no argument, see AttributeMayNotHaveEmptyParenthesesRule.
     *
     * @return list<array{int, string}>
     */
    public function getAttributesWithEmptyParentheses(): array
    {
        return $this->fileSyntaxAnalysis->attributesWithEmptyParentheses;
    }

    /**
     * Lower-cased namespaced names of the functions the file traversed last
     * declares unconditionally, see {@see UnconditionallyDeclaredFunctions}.
     *
     * @return array<string, true>
     */
    public function getFileFunctions(): array
    {
        return $this->fileReferenceAnalysis->declaredFunctions;
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

    /**
     * The namespaced function name carried by a function fallback marker, or
     * null when the reference is a plain name.
     */
    public static function parseFunctionFallbackMarker(string $reference): ?string
    {
        return str_starts_with($reference, self::FUNCTION_FALLBACK_MARKER_PREFIX)
            ? substr($reference, strlen(self::FUNCTION_FALLBACK_MARKER_PREFIX))
            : null;
    }

    /** The resolved function name carried by a file reference, or null. */
    public static function parseFunctionReference(string $reference): ?string
    {
        return str_starts_with($reference, self::FUNCTION_REFERENCE_PREFIX)
            ? substr($reference, strlen(self::FUNCTION_REFERENCE_PREFIX))
            : null;
    }

    public function enterNode(Node $node): null
    {
        if (! isset(self::ENTER_NODES[$node::class])) {
            return null;
        }

        // This ordering is about the instanceof tests in this method, not the
        // traversal: the traverser still enters a statement before the
        // expressions inside it. Variable is the most frequent node class, so
        // testing it first spares every variable the Stmt and FunctionLike
        // checks and the collectNodeAnalysis() call. Only `$this` and
        // superglobals are recorded; outside a class-like or function-like
        // scope both handlers record nothing, so no scope check is needed.
        if ($node instanceof Variable) {
            if ($node->name === 'this') {
                $this->markThisUsage();
            } elseif (is_string($node->name) && isset(self::SUPERGLOBALS[$node->name])) {
                $this->addSuperglobal('$' . $node->name);
            }

            return null;
        }

        // The scope-tracking node types are all statements, so the far more
        // frequent expression/name/identifier nodes skip their checks with a
        // single instanceof.
        if ($node instanceof Stmt) {
            if ($node instanceof Namespace_) {
                $this->namespaceAnalysis = new NamespaceAnalysis((string) $node->name);

                return null;
            }

            if ($node instanceof Use_) {
                foreach ($node->uses as $use) {
                    $name = $use->name->toString();

                    $this->namespaceAnalysis->uses[$name] = true;

                    if ($node->type === Use_::TYPE_NORMAL) {
                        $this->namespaceAnalysis->classUses[$name] = true;
                    }
                }

                return null;
            }

            if ($node instanceof GroupUse) {
                $prefix = $node->prefix->toString();

                foreach ($node->uses as $use) {
                    $name = $prefix . '\\' . $use->name->toString();

                    $this->namespaceAnalysis->uses[$name] = true;

                    // A typed group (`use function A\{b, c}`) types the group,
                    // a mixed one (`use A\{B, function c}`) each item.
                    $type = $node->type === Use_::TYPE_UNKNOWN ? $use->type : $node->type;

                    if ($type === Use_::TYPE_NORMAL) {
                        $this->namespaceAnalysis->classUses[$name] = true;
                    }
                }

                return null;
            }

            if ($node instanceof Function_) {
                $this->startFunctionLikeAnalysis($node);

                return null;
            }

            if ($node instanceof ClassLike) {
                $this->startClassLikeAnalysis($node);

                return null;
            }

            if ($node instanceof ClassMethod) {
                $this->activeMethodAnalyses[] = new MethodAnalysis($node->name->toLowerString(), $node->isStatic());

                return null;
            }

            if (isset(self::MEMBER_NODES[$node::class])) {
                $this->collectMember($node);

                return null;
            }
        } elseif ($node instanceof FunctionLike) {
            $this->startFunctionLikeAnalysis($node);

            return null;
        }

        $this->collectNodeAnalysis($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (! isset(self::LEAVE_NODES[$node::class])) {
            return null;
        }

        // Both instantiation handlers run on leave, once the NameResolver
        // has resolved the nested name nodes (e.g. Base::class inside the
        // class expression). They only match expressions, and ClassMethod /
        // ClassLike are statements, so one instanceof splits the two groups.
        if ($node instanceof Expr) {
            if ($node instanceof Closure || $node instanceof ArrowFunction) {
                $this->finishFunctionLikeAnalysis();

                return null;
            }

            // Instantiations are tracked separately from plain references:
            // `new` on an abstract class is fatal, so instantiation is the one
            // usage that requires an extended class to stay concrete — type
            // hints, instanceof checks, and ::class constants all keep working
            // once a class becomes abstract.
            if ($node instanceof New_) {
                $this->collectInstantiation($node);

                return null;
            }

            // Left after its operands, once the NameResolver has resolved
            // the names of the `X::class` operands.
            if ($node instanceof Concat) {
                $this->collectConcatReference($node);

                return null;
            }

            // The exact-class LEAVE_NODES filter and the returns above leave
            // only these three call nodes on this path.
            /** @phpstan-var StaticCall|MethodCall|NullsafeMethodCall $node */
            $this->collectObjectBindingRequirement($node);

            if ($node instanceof StaticCall) {
                $this->collectImplicitThisMethodCall($node);

                return null;
            }

            // A ReflectionClass construction call instantiates the reflected
            // class when the reflection target is statically resolvable. The
            // `new` receiver is checked first: it is the rare shape, so the
            // common method call skips the name lowering entirely.
            if (
                $node->var instanceof New_
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

        // Left after its hooks, once the NameResolver has resolved their
        // parameter types; the rest of the property is collected on enter.
        if ($node instanceof Property) {
            $analysis = $this->declaringClassLikeAnalysis();

            if ($this->withSignatures && $analysis instanceof ClassLikeAnalysis) {
                $this->collectSetHookSignatures($analysis, $node);
            }

            return null;
        }

        if ($node instanceof Function_) {
            $this->finishFunctionLikeAnalysis();

            return null;
        }

        // Every remaining LEAVE_NODES entry is a class-like statement.
        /** @phpstan-var ClassLike $node */

        if ($node instanceof Enum_) {
            $this->collectEnumCases($node);
        }

        /** @var ClassLikeAnalysis $analysis */
        $analysis = array_pop($this->activeClassLikeAnalyses);

        // Anonymous classes never become ClassNodes, but the class they
        // extend, the interfaces they implement, and the traits they use
        // are still used within the scanned paths, and their members and
        // body facts are collected like a named class's.
        if ($node instanceof Class_ && $node->isAnonymous()) {
            // Its own (nameless) entry is already popped, so the innermost
            // active names are the named scopes declaring it; they also
            // resolve its layer, as they do for an anonymous function.
            $this->fileClassLikes[] = [
                $node,
                $analysis,
                $this->innermostActiveClassLikeName(),
                $this->innermostActiveFunctionName(),
            ];
        } else {
            $this->fileClassLikes[] = [$node, $analysis, null, null];
        }

        return null;
    }

    /** @param Node[] $nodes */
    public function afterTraverse(array $nodes): null
    {
        $this->fileReferenceAnalysis->declaredFunctions = UnconditionallyDeclaredFunctions::names($nodes);

        foreach ($this->fileClassLikes as [$classLike, $analysis, $enclosingClassName, $enclosingFunctionName]) {
            if ($classLike instanceof Class_ && $classLike->isAnonymous()) {
                $this->collectAnonymousClass($classLike, $analysis, $enclosingClassName, $enclosingFunctionName);
            } else {
                $this->collectClassLike($classLike, $analysis);
            }
        }

        foreach ($this->fileFunctionLikeAnalyses as $fileFunctionLikeAnalysis) {
            $this->collectFunctionLike($fileFunctionLikeAnalysis);
        }

        // A fallback marker stands for this file's calls of its short name, so
        // a global function also called by resolved name here is referenced
        // by name.
        $globalFunctionCalls = array_intersect_key(
            $this->fileReferenceAnalysis->globalFunctionCalls,
            $this->fileReferenceAnalysis->fallbackNames
        );

        foreach ($globalFunctionCalls as $globalFunctionCall) {
            $this->fileReferenceAnalysis->references[self::FUNCTION_REFERENCE_PREFIX . $globalFunctionCall] = true;
        }

        if ($this->withFileReferences && $this->fileReferenceAnalysis->references !== []) {
            $this->fileReferences[$this->fileSyntaxAnalysis->file] = array_keys(
                $this->fileReferenceAnalysis->references,
            );
        }

        if ($this->withFileReferences && $this->fileReferenceAnalysis->instantiations !== []) {
            $this->fileInstantiations[$this->fileSyntaxAnalysis->file] = array_keys(
                $this->fileReferenceAnalysis->instantiations,
            );
        }

        $this->fileClassLikes           = [];
        $this->fileFunctionLikeAnalyses = [];

        return null;
    }

    /**
     * A named class-like seeds its dependencies with the namespace imports.
     * An anonymous class does not: like a function-like's, its file's imports
     * belong to the file (and the named class-like declaring it), not to it.
     */
    private function startClassLikeAnalysis(ClassLike $classLike): void
    {
        $methodStaticFlags = [];

        foreach ($classLike->getMethods() as $method) {
            $methodStaticFlags[$method->name->toLowerString()] = $method->isStatic();
        }

        $classLikeName = $classLike->name instanceof Identifier
            ? $this->resolveClassName($classLike)
            : null;

        $parent = $classLike instanceof Class_ && $classLike->extends instanceof Name
            ? $classLike->extends->toString()
            : null;

        $classLikeAnalysis = new ClassLikeAnalysis(
            $classLike instanceof Interface_,
            $classLikeName,
            $this->createClassLikeScope($classLike, $classLikeName, $parent),
            $methodStaticFlags,
            count($this->activeFunctionLikeAnalyses),
            $parent,
        );

        if ($classLike->name instanceof Identifier) {
            $classLikeAnalysis->dependencies      = $this->namespaceAnalysis->uses;
            $classLikeAnalysis->classDependencies = $this->namespaceAnalysis->classUses;
        }

        $this->activeClassLikeAnalyses[] = $classLikeAnalysis;
    }

    /**
     * The analysis of the class-like declaring the member being entered: the
     * innermost active class-like, named or anonymous.
     */
    private function declaringClassLikeAnalysis(): ?ClassLikeAnalysis
    {
        $analysis = end($this->activeClassLikeAnalyses);

        return $analysis instanceof ClassLikeAnalysis ? $analysis : null;
    }

    /**
     * Members other than methods carry no body facts, so they are recorded
     * completely the moment the traverser enters them.
     */
    private function collectMember(Stmt $stmt): void
    {
        $analysis = $this->declaringClassLikeAnalysis();

        if (! $analysis instanceof ClassLikeAnalysis) {
            return;
        }

        if ($stmt instanceof Property) {
            $visibility            = $this->resolveVisibilityName($stmt);
            $hasExplicitVisibility = VisibilityFlagChecker::hasExplicitVisibilityFlag($stmt->flags);

            foreach ($stmt->props as $prop) {
                if ($this->withSignatures) {
                    $this->collectSignatures(
                        $analysis,
                        $stmt->type,
                        (string) $prop->name,
                        ClassSignature::PROPERTY,
                        $visibility
                    );
                }

                $analysis->properties[] = new PropertyNode(
                    name:                 (string) $prop->name,
                    visibility:           $visibility,
                    hasExplicitVisibility: $hasExplicitVisibility,
                    line:                 $prop->getStartLine(),
                );
            }

            return;
        }

        if ($stmt instanceof ClassConst) {
            $visibility            = $this->resolveVisibilityName($stmt);
            $hasExplicitVisibility = VisibilityFlagChecker::hasExplicitVisibilityFlag($stmt->flags);

            foreach ($stmt->consts as $const) {
                $analysis->constants[] = new ConstantNode(
                    name:                 (string) $const->name,
                    visibility:           $visibility,
                    hasExplicitVisibility: $hasExplicitVisibility,
                    line:                 $const->getStartLine(),
                );
            }

            return;
        }

        if ($stmt instanceof TraitUse && ! $analysis->isInterface) {
            foreach ($stmt->traits as $trait) {
                $analysis->traits[] = $trait->toString();
            }
        }
    }

    /**
     * A set hook may accept a wider type than its property declares, so an
     * explicitly typed hook parameter is recorded as a parameter of its own,
     * under the visibility the property is set with. A get hook, and a set
     * hook without a typed parameter, take the property's type, which is
     * recorded with the property, or the promoted parameter, itself.
     */
    private function collectSetHookSignatures(ClassLikeAnalysis $classLikeAnalysis, Property|Param $node): void
    {
        $visibility = match (true) {
            $node->isPrivateSet()   => 'private',
            $node->isProtectedSet() => 'protected',
            default                 => $this->resolveVisibilityName($node),
        };

        // only a set hook takes a parameter
        foreach ($node->hooks as $hook) {
            foreach ($hook->params as $param) {
                $this->collectSignatures(
                    $classLikeAnalysis,
                    $param->type,
                    $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : '',
                    ClassSignature::PARAM,
                    $visibility
                );
            }
        }
    }

    /**
     * Collected on leaving the enum, while its scope is still active: by then
     * the NameResolver has resolved every name in the case values, and a case
     * value may reference an enum constant declared after the case.
     */
    private function collectEnumCases(Enum_ $enum): void
    {
        /** @var ClassLikeAnalysis $analysis The enum's own analysis */
        $analysis  = $this->declaringClassLikeAnalysis();
        $enumCases = [];
        $constants = [];

        foreach ($enum->stmts as $stmt) {
            if ($stmt instanceof EnumCase) {
                $enumCases[] = $stmt;
            } elseif ($stmt instanceof ClassConst) {
                foreach ($stmt->consts as $const) {
                    $constants[$const->name->toString()] = $const->value;
                }
            }
        }

        $evaluator = $constants === []
            ? $this->constExprEvaluator
            : $this->createEnumConstantEvaluator($constants);

        foreach ($enumCases as $enumCase) {
            $analysis->enumCases[] = new EnumCaseNode(
                name:       (string) $enumCase->name,
                line:       $enumCase->getStartLine(),
                value:      $this->resolveEnumCaseValue($enumCase->expr, $evaluator),
                sharesLine: EnumCaseLine::sharesLine($this->fileSyntaxAnalysis->tokens, $enumCase),
            );
        }
    }

    /**
     * An evaluator that also resolves the enum's own constants, fetched as
     * `self::X` or by the enum's own name. Each is evaluated at most once; a
     * constant is dropped from the list before its evaluation, so a reference
     * cycle back to it, or a fetch after its evaluation failed, stays
     * unresolved like any unknown constant.
     *
     * @param array<string, Expr> $constants The enum's constant expressions, by name
     */
    private function createEnumConstantEvaluator(array $constants): ConstExprEvaluator
    {
        $enumName = $this->declaringClassLikeAnalysis()?->name;
        $values   = [];

        $evaluator = new ConstExprEvaluator(function (Expr $expr) use (
            &$evaluator,
            &$constants,
            &$values,
            $enumName,
        ): mixed {
            if (
                $expr instanceof ClassConstFetch
                && $expr->class instanceof Name
                && $expr->name instanceof Identifier
                && $this->resolveClassLikeName($expr->class) === $enumName
            ) {
                $name = $expr->name->toString();

                if (array_key_exists($name, $values)) {
                    return $values[$name];
                }

                if (isset($constants[$name])) {
                    $constant = $constants[$name];
                    unset($constants[$name]);

                    return $values[$name] = $evaluator?->evaluateDirectly($constant);
                }
            }

            return $this->evaluateClassNameFetch($expr);
        });

        return $evaluator;
    }

    /**
     * Unlike a class-like, a function-like does not seed its dependencies
     * with the namespace imports: a file may declare hundreds of functions,
     * and its imports belong to the file, not to each of them. Only what the
     * signature and body reference is recorded.
     */
    private function startFunctionLikeAnalysis(FunctionLike $functionLike): void
    {
        $functionLikeAnalysis = new FunctionLikeAnalysis(
            $functionLike,
            $this->innermostActiveClassLikeName(),
            $functionLike instanceof Function_
                ? $this->resolveFunctionDeclarationName($functionLike)
                : $this->innermostActiveFunctionName(),
        );

        $this->activeFunctionLikeAnalyses[] = $functionLikeAnalysis;
        $this->fileFunctionLikeAnalyses[]   = $functionLikeAnalysis;
    }

    private function collectObjectBindingRequirement(StaticCall|MethodCall|NullsafeMethodCall $call): void
    {
        $anonymousFunction = $call instanceof StaticCall
            ? ObjectBoundAnonymousFunction::fromStaticCall($call)
            : ObjectBoundAnonymousFunction::fromMethodCall($call);

        if (! $anonymousFunction instanceof Closure && ! $anonymousFunction instanceof ArrowFunction) {
            return;
        }

        for ($index = count($this->fileFunctionLikeAnalyses) - 1; $index >= 0; $index--) {
            $functionLikeAnalysis = $this->fileFunctionLikeAnalyses[$index];

            if ($functionLikeAnalysis->functionLike === $anonymousFunction) {
                $functionLikeAnalysis->requiresObjectBinding = true;

                return;
            }
        }
    }

    private function innermostActiveClassLikeName(): ?string
    {
        for ($index = count($this->activeClassLikeAnalyses) - 1; $index >= 0; $index--) {
            if ($this->activeClassLikeAnalyses[$index]->name !== null) {
                return $this->activeClassLikeAnalyses[$index]->name;
            }
        }

        return null;
    }

    private function innermostActiveFunctionName(): ?string
    {
        for ($index = count($this->activeFunctionLikeAnalyses) - 1; $index >= 0; $index--) {
            $functionLike = $this->activeFunctionLikeAnalyses[$index]->functionLike;

            if ($functionLike instanceof Function_) {
                return $this->resolveFunctionDeclarationName($functionLike);
            }
        }

        return null;
    }

    /**
     * The method's complexity is final once its body has been left, so the
     * MethodNode is built here; a constructor's promoted parameters are the
     * class's properties.
     */
    private function finishMethodAnalysis(ClassMethod $classMethod): void
    {
        $methodAnalysis = array_pop($this->activeMethodAnalyses);
        $analysis       = $this->declaringClassLikeAnalysis();

        if (! $analysis instanceof ClassLikeAnalysis) {
            return;
        }

        $analysis->methods[] = new MethodNode(
            name:                 (string) $classMethod->name,
            visibility:           $this->resolveVisibilityName($classMethod),
            hasReturnType:        $classMethod->returnType instanceof Node,
            isStatic:             $classMethod->isStatic(),
            paramCount:           count($classMethod->params),
            cyclomaticComplexity: $methodAnalysis->cyclomaticComplexity ?? 1,
            lineCount:            $this->calculateLineCount($classMethod),
            hasExplicitVisibility: VisibilityFlagChecker::hasExplicitVisibilityFlag($classMethod->flags),
            line:                 $classMethod->getStartLine(),
            isMagic:              $classMethod->isMagic(),
        );

        if ($this->withSignatures) {
            $this->collectMethodSignatures($analysis, $classMethod);
        }

        if ($classMethod->name->toLowerString() !== '__construct') {
            return;
        }

        foreach ($classMethod->params as $param) {
            if (
                ! $param->isPromoted()
                || ! $param->var instanceof Variable
                || ! is_string($param->var->name)
            ) {
                continue;
            }

            $analysis->properties[] = new PropertyNode(
                name:                  (string) $param->var->name,
                visibility:            $this->resolveVisibilityName($param),
                hasExplicitVisibility: VisibilityFlagChecker::hasExplicitVisibilityFlag($param->flags),
                line:                  $param->getStartLine(),
            );
        }
    }

    private function collectMethodSignatures(ClassLikeAnalysis $classLikeAnalysis, ClassMethod $classMethod): void
    {
        $visibility = $this->resolveVisibilityName($classMethod);

        foreach ($classMethod->params as $param) {
            $name = $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : '';

            // a promoted parameter is recorded as the property it declares, set hook
            // included, and also as a parameter when private, since callers must
            // still pass its type
            if ($param->isPromoted()) {
                $this->collectSignatures(
                    $classLikeAnalysis,
                    $param->type,
                    $name,
                    ClassSignature::PROPERTY,
                    $this->resolveVisibilityName($param),
                    true
                );
                $this->collectSetHookSignatures($classLikeAnalysis, $param);

                if (! $param->isPrivate()) {
                    continue;
                }
            }

            $this->collectSignatures(
                $classLikeAnalysis,
                $param->type,
                $name,
                ClassSignature::PARAM,
                $visibility,
                $param->isPromoted()
            );
        }

        $this->collectSignatures(
            $classLikeAnalysis,
            $classMethod->returnType,
            $classMethod->name->toString(),
            ClassSignature::METHOD,
            $visibility
        );
    }

    private function collectSignatures(
        ClassLikeAnalysis $classLikeAnalysis,
        ?Node $type,
        string $name,
        string $kind,
        string $visibility,
        bool $isPromoted = false,
    ): void {
        if ($type instanceof Name) {
            // `parent` exposes the direct parent class just as its name would;
            // self and static name the class-like itself, which exposes nothing
            $typeName = match (true) {
                ! $type->isSpecialClassName()        => $type->toString(),
                $type->toLowerString() === 'parent' => $classLikeAnalysis->parent,
                default                              => null,
            };

            if ($typeName !== null) {
                $classLikeAnalysis->signatures[] = new ClassSignature(
                    $typeName,
                    $name,
                    $kind,
                    $visibility,
                    $type->getStartLine(),
                    $isPromoted
                );
            }

            return;
        }

        if ($type instanceof NullableType) {
            $this->collectSignatures($classLikeAnalysis, $type->type, $name, $kind, $visibility, $isPromoted);

            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $innerType) {
                $this->collectSignatures($classLikeAnalysis, $innerType, $name, $kind, $visibility, $isPromoted);
            }
        }
    }

    /**
     * Roll a completed function-like's body facts into its lexical parent.
     * Class-like facts are still collected directly during traversal, so a
     * top-level function-like has no additional merge target here.
     */
    private function finishFunctionLikeAnalysis(): void
    {
        $activeCount = count($this->activeFunctionLikeAnalyses);

        if ($activeCount === 0) {
            return;
        }

        $functionLikeAnalysis = array_pop($this->activeFunctionLikeAnalyses);

        if ($activeCount === 1) {
            return;
        }

        $parent = $this->activeFunctionLikeAnalyses[$activeCount - 2];

        array_push($parent->functionCallNames, ...$functionLikeAnalysis->functionCallNames);

        $parent->dependencies       += $functionLikeAnalysis->dependencies;
        $parent->superglobals       += $functionLikeAnalysis->superglobals;
        $parent->languageConstructs += $functionLikeAnalysis->languageConstructs;

        if ($functionLikeAnalysis->cyclomaticComplexity > 1) {
            $parent->cyclomaticComplexity += $functionLikeAnalysis->cyclomaticComplexity - 1;
        }
    }

    private function collectNodeAnalysis(Node $node): void
    {
        if ($node instanceof Int_ || $node instanceof Float_) {
            if (! $this->withFileAnalysis) {
                return;
            }

            $rawValue = $node->getAttribute('rawValue');

            // Parser-created scalar nodes always carry rawValue. Programmatic
            // nodes may not, and non-finite floats cannot be cache-serialized.
            if (
                is_string($rawValue)
                && ($node instanceof Int_ || is_finite($node->value))
            ) {
                $this->fileSyntaxAnalysis->numericLiterals[] = [$node->getStartLine(), $rawValue, $node->value];
            }

            return;
        }

        // A concatenation spells one string, so its literal operands are
        // fragments of a name, not names on their own. Entered before its
        // operands, it keeps them from the String_ branch below; the whole
        // value is collected on leave, see collectConcatReference(). The name
        // passed to function_exists() stays a probe at every nesting level.
        if ($node instanceof Concat) {
            $isFunctionExistsCheck = $node->hasAttribute(self::FUNCTION_EXISTS_CHECK_ATTRIBUTE);

            foreach ([$node->left, $node->right] as $operand) {
                if ($operand instanceof String_) {
                    $operand->setAttribute(self::FUNCTION_EXISTS_CHECK_ATTRIBUTE, true);
                } elseif ($operand instanceof Concat) {
                    $operand->setAttribute(
                        $isFunctionExistsCheck ? self::FUNCTION_EXISTS_CHECK_ATTRIBUTE : self::CONCAT_OPERAND_ATTRIBUTE,
                        true
                    );
                }
            }

            return;
        }

        // A class-name-shaped string literal may feed `new $class`,
        // `$obj instanceof $class`, class_exists(), container ids, and so on.
        // Whether it appears inside a class-like or in procedural code, treat
        // it as a file-level reference so the named class-like stays alive.
        if ($node instanceof String_) {
            if (! $node->hasAttribute(self::FUNCTION_EXISTS_CHECK_ATTRIBUTE)) {
                $this->collectStringReference($node->value);
            }

            return;
        }

        if ($node instanceof FullyQualified) {
            $name = $node->toString();

            if (isset(self::KEYWORD_CONSTANTS[strtolower($name)])) {
                return;
            }

            if ($this->activeClassLikeAnalyses === [] && ! $node->hasAttribute(self::NON_CLASS_NAME_ATTRIBUTE)) {
                // Outside any class-like scope — procedural functions and
                // top-level statements — a class-like reference still keeps
                // the referenced class-like alive.
                $this->fileReferenceAnalysis->references[$name] = true;
            }

            $this->addDependency($name, ! $node->hasAttribute(self::NON_CLASS_NAME_ATTRIBUTE));

            return;
        }

        // Entered before its name, so the FullyQualified branch above sees
        // the mark.
        if ($node instanceof ConstFetch) {
            $name = $node->name;
            $name->setAttribute(self::NON_CLASS_NAME_ATTRIBUTE, true);

            if ($this->withFileAnalysis) {
                $this->collectKeywordConstant($name);
            }

            if ($this->activeClassLikeAnalyses !== [] && ! isset(self::KEYWORD_CONSTANTS[$name->toLowerString()])) {
                // An unqualified fetch in a namespace is not a FullyQualified
                // node: PHP fetches the namespaced constant when it exists and
                // the global one otherwise, which is not known here, so both
                // candidates are recorded.
                $namespacedName = $name->getAttribute('namespacedName');
                $constant       = $name->toString();

                foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
                    if ($namespacedName instanceof Name) {
                        $activeClassLikeAnalysis->constantFetches[$namespacedName->toString()] = true;
                    }

                    $activeClassLikeAnalysis->constantFetches[$constant] = true;
                }
            }

            return;
        }

        if ($node instanceof FuncCall) {
            $name = $node->name;

            if (! $name instanceof Name) {
                return;
            }

            $name->setAttribute(self::NON_CLASS_NAME_ATTRIBUTE, true);

            $functionName = $name->toLowerString();

            // Entered before its arguments, so the name string is marked before
            // the String_ branch sees it — also outside any scope, where a
            // top-level `if (! function_exists(...))` guard usually sits. A name
            // bound by a partial application is still only probed, once the
            // closure runs; a placeholder is no argument, so nothing is marked.
            if ($functionName === 'function_exists') {
                $node->getArg('function', 0)?->value->setAttribute(self::FUNCTION_EXISTS_CHECK_ATTRIBUTE, true);
            }

            if ($this->activeClassLikeAnalyses === [] && $this->activeFunctionLikeAnalyses === []) {
                // Inside a function-like the call is one of that scope's
                // function calls instead: recorded here as well, a function
                // calling only itself by resolved name would keep itself alive.
                if ($name instanceof FullyQualified) {
                    $this->fileReferenceAnalysis->references[
                        self::FUNCTION_REFERENCE_PREFIX . $name->toString()
                    ] = true;

                    return;
                }

                // A top-level unqualified call in a namespace is not a
                // FullyQualified node, so it is recorded here as a fallback
                // marker, keeping the function PHP calls alive.
                $namespacedName = $name->getAttribute('namespacedName');

                if ($namespacedName instanceof Name) {
                    $this->addFunctionFallback($name, $namespacedName);
                }

                return;
            }

            // PHP 8.4 generalized exit/die (e.g. named arguments) parse as
            // FuncCall instead of Exit_, but remain language constructs
            if ($functionName === 'exit' || $functionName === 'die') {
                $this->addLanguageConstruct($functionName);
            } else {
                $this->addFunctionCallName($name);
            }

            return;
        }

        // An attribute on a global constant (`#[Deprecated] const A = 1;`) is
        // the one attribute outside every scope, and is still collected.
        if (
            $this->activeClassLikeAnalyses === []
            && $this->activeFunctionLikeAnalyses === []
            && ! $node instanceof Attribute
        ) {
            return;
        }

        // Branch nodes (conditions, loops, boolean operators) are among the
        // most frequent remaining node types, so they dispatch on one hash
        // lookup before the rarer per-type checks below.
        $nodeClass = $node::class;

        if (isset(self::COMPLEXITY_BRANCH_NODES[$nodeClass])) {
            foreach ($this->activeMethodAnalyses as $activeMethodAnalysis) {
                $activeMethodAnalysis->cyclomaticComplexity++;
            }

            $activeFunctionLikeCount = count($this->activeFunctionLikeAnalyses);

            if ($activeFunctionLikeCount > 0) {
                $this->activeFunctionLikeAnalyses[$activeFunctionLikeCount - 1]->cyclomaticComplexity++;
            }

            return;
        }

        // The backtick operator is identical to shell_exec(), so a ban on that
        // function covers it too.
        if ($node instanceof ShellExec) {
            $this->addFunctionCallName(new FullyQualified('shell_exec'));

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

        // ReflectionAttribute::newInstance() instantiates the attribute class,
        // which is fatal on an abstract class, so an attribute counts as an
        // instantiation. The NameResolver resolved its name on entering the
        // node carrying the attribute.
        if ($node instanceof Attribute) {
            $attributeName = $node->name->toString();

            $this->fileReferenceAnalysis->instantiations[$attributeName] = true;

            if (
                $this->withFileAnalysis
                && EmptyParentheses::tokenRangeAfter(
                    $this->fileSyntaxAnalysis->tokens,
                    $node->name->getEndTokenPos(),
                ) !== null
            ) {
                $this->fileSyntaxAnalysis->attributesWithEmptyParentheses[] = [$node->getStartLine(), $attributeName];
            }

            return;
        }

        $languageConstruct = self::LANGUAGE_CONSTRUCT_NODES[$nodeClass] ?? null;

        if ($languageConstruct !== null) {
            $this->addLanguageConstruct($languageConstruct);
        }
    }

    /**
     * Records a `true`, `false`, or `null` fetch whose spelling is not the
     * canonical lowercase. The NameResolver has already replaced an unqualified
     * name outside a namespace with a FullyQualified clone, so whether the `\`
     * was written is read from the source span instead: it is one character
     * longer than the name. Other spellings, such as `namespace\TRUE`, are
     * out of scope.
     */
    private function collectKeywordConstant(Name $name): void
    {
        $spelling = $name->toString();

        if (isset(self::KEYWORD_CONSTANTS[$spelling])) {
            return;
        }

        $length = strlen($spelling);

        if ($length !== 4 && $length !== 5) {
            return;
        }

        $keyword = strtolower($spelling);

        if (! isset(self::KEYWORD_CONSTANTS[$keyword])) {
            return;
        }

        $spanLength = $name->getEndFilePos() - $name->getStartFilePos() + 1;

        if ($spanLength === $length + 1) {
            $spelling = '\\' . $spelling;
        } elseif ($spanLength !== $length) {
            return;
        }

        $this->fileSyntaxAnalysis->nonCanonicalKeywordConstants[] = [$name->getStartLine(), $spelling];
    }

    private function collectInstantiation(New_ $new): void
    {
        $class = $new->class;

        if ($class instanceof Name) {
            $className = $this->resolveClassLikeName($class);

            if ($className !== null) {
                $this->fileReferenceAnalysis->instantiations[$className] = true;
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
            $this->fileReferenceAnalysis->instantiations[$className] = true;
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
        return $this->declaringClassLikeAnalysis()?->instantiationScope[$name->toLowerString()] ?? null;
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
    private function createClassLikeScope(ClassLike $classLike, ?string $classLikeName, ?string $parent): array
    {
        if ($classLikeName === null) {
            return ['self' => null, 'static' => null, 'parent' => $parent];
        }

        if ($classLike instanceof Trait_) {
            return [
                'self'   => self::deferredInstantiationMarker('self', $classLikeName),
                'static' => self::deferredInstantiationMarker('static', $classLikeName),
                'parent' => self::deferredInstantiationMarker('parent', $classLikeName),
            ];
        }

        return [
            'self'   => $classLikeName,
            'static' => self::deferredInstantiationMarker('static', $classLikeName),
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
            $this->fileReferenceAnalysis->instantiations[$reflectionTarget] = true;
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
     * enum case and for values that depend on symbols outside the enum
     * (global constants, other class constants), which the analyser cannot
     * evaluate.
     */
    private function resolveEnumCaseValue(?Expr $expr, ConstExprEvaluator $evaluator): int|string|null
    {
        if (! $expr instanceof Expr) {
            return null;
        }

        try {
            $value = $evaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * The constant expression evaluator's fallback: `X::class` of a
     * resolvable class name. Anything else the evaluator cannot evaluate
     * itself is unresolvable.
     *
     * @throws ConstExprEvaluationException
     */
    private function evaluateClassNameFetch(Expr $expr): string
    {
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
    }

    /**
     * Collect the value of a concatenation as the string literal spelling it
     * would be: `'App\\' . 'Service'` and `__NAMESPACE__ . '\Service'` both
     * reference App\Service, never the name a fragment spells on its own.
     *
     * A concatenation that depends on a runtime value has no value to
     * collect. Its literal operands, and the nested concatenations that did
     * resolve, are then collected on their own.
     */
    private function collectConcatReference(Concat $concat): void
    {
        if ($concat->hasAttribute(self::FUNCTION_EXISTS_CHECK_ATTRIBUTE)) {
            return;
        }

        $left  = $this->resolveConcatOperand($concat->left);
        $right = $left === null ? null : $this->resolveConcatOperand($concat->right);

        if ($left !== null && $right !== null) {
            if ($concat->hasAttribute(self::CONCAT_OPERAND_ATTRIBUTE)) {
                $concat->setAttribute(self::CONCAT_VALUE_ATTRIBUTE, $left . $right);
            } else {
                $this->collectStringReference($left . $right);
            }

            return;
        }

        foreach ([$concat->left, $concat->right] as $operand) {
            $value = $operand instanceof String_
                ? $operand->value
                : $operand->getAttribute(self::CONCAT_VALUE_ATTRIBUTE);

            if (is_string($value)) {
                $this->collectStringReference($value);
            }
        }
    }

    /**
     * The string an operand of a concatenation spells: a literal,
     * `__NAMESPACE__`, a nested concatenation, or `X::class`. Null for
     * anything else.
     */
    private function resolveConcatOperand(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        if ($expr instanceof NamespaceMagicConst) {
            return $this->namespaceAnalysis->name;
        }

        // A nested concatenation is left before the outer one, which finds
        // its value here.
        if ($expr instanceof Concat) {
            $value = $expr->getAttribute(self::CONCAT_VALUE_ATTRIBUTE);

            return is_string($value) ? $value : null;
        }

        // Most other operands are runtime values, which the evaluator can
        // only reject by throwing, so it is asked for class constants only.
        return $expr instanceof ClassConstFetch ? $this->resolveClassNameExpr($expr) : null;
    }

    /**
     * Record a class-name-shaped string as a file reference: the class-like
     * it names, and the function, as it can also be invoked as a callable.
     */
    private function collectStringReference(string $value): void
    {
        // A leading `\` is a valid fully-qualified spelling
        // (`'\App\Contract'`); strip it so the stored name matches the
        // ClassNode::$className form used for usage lookups.
        $value = $this->stripLeadingNamespaceSeparator($value);

        $separatorPosition = strpos($value, '::');

        // A static callable string ('App\Handler::run') references its class
        // only: the method name is neither a class nor a function. A method
        // part that is not an identifier ('App\A::', 'App\A::123',
        // 'App\A::B::run') is not a callable.
        if ($separatorPosition !== false) {
            $staticCallableClass = substr($value, 0, $separatorPosition);

            if (
                preg_match(self::METHOD_NAME_PATTERN, substr($value, $separatorPosition + 2)) === 1
                && preg_match(self::CLASS_LIKE_STRING_PATTERN, $staticCallableClass) === 1
            ) {
                $this->fileReferenceAnalysis->references[$staticCallableClass] = true;
            }

            return;
        }

        if (
            preg_match(self::CLASS_LIKE_STRING_PATTERN, $value) === 1
            && ! isset(self::KEYWORD_CONSTANTS[strtolower($value)])
        ) {
            $this->fileReferenceAnalysis->references[$value]                                   = true;
            $this->fileReferenceAnalysis->references[self::FUNCTION_REFERENCE_PREFIX . $value] = true;
        }
    }

    /**
     * `'\App\X'` and `'App\X'` name the same class; the collector stores the
     * latter form so usage keys line up with ClassNode::$className.
     */
    private function stripLeadingNamespaceSeparator(string $name): string
    {
        return str_starts_with($name, '\\') ? substr($name, 1) : $name;
    }

    private function addDependency(string $dependency, bool $isClassName): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->dependencies[$dependency] = true;

            if ($isClassName) {
                $activeClassLikeAnalysis->classDependencies[$dependency] = true;
            }
        }

        $activeFunctionLikeCount = count($this->activeFunctionLikeAnalyses);

        if ($activeFunctionLikeCount > 0) {
            $this->activeFunctionLikeAnalyses[$activeFunctionLikeCount - 1]->dependencies[$dependency] = true;
        }
    }

    private function addFunctionCallName(Name $functionCallName): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->functionCallNames[] = $functionCallName;
        }

        $activeFunctionLikeCount = count($this->activeFunctionLikeAnalyses);

        if ($activeFunctionLikeCount > 0) {
            $this->activeFunctionLikeAnalyses[$activeFunctionLikeCount - 1]->functionCallNames[] = $functionCallName;
        }
    }

    private function addSuperglobal(string $superglobal): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->superglobals[$superglobal] = true;
        }

        $activeFunctionLikeCount = count($this->activeFunctionLikeAnalyses);

        if ($activeFunctionLikeCount > 0) {
            $this->activeFunctionLikeAnalyses[$activeFunctionLikeCount - 1]->superglobals[$superglobal] = true;
        }
    }

    /**
     * `$this` belongs to every anonymous function entered since the innermost
     * class-like, as a non-static anonymous function captures it from its
     * enclosing scope through any number of nested non-static anonymous
     * functions.
     */
    private function markThisUsage(): void
    {
        $depth = $this->declaringClassLikeAnalysis()->functionLikeDepthAtEntry ?? 0;

        for ($index = count($this->activeFunctionLikeAnalyses) - 1; $index >= $depth; $index--) {
            $this->activeFunctionLikeAnalyses[$index]->usesThis = true;
        }
    }

    /**
     * `self::method()`, `static::method()`, and a call through the enclosing
     * class's own name need `$this` when the current class-like declares a
     * non-static method. Only local declarations are inspected; unresolved
     * methods retain the assumption that they are static. The method lookup
     * runs first, so the class name is only compared for a call that names
     * a locally declared non-static method.
     *
     * `parent::method()` forwards `$this` to a non-static parent method. The
     * parent class may live in another file, so only a call to the method
     * the enclosing method overrides is decided here: an override is static
     * exactly when the method it overrides is, so inside a non-static method
     * such a call counts as `$this` usage.
     */
    private function collectImplicitThisMethodCall(StaticCall $staticCall): void
    {
        if (! $staticCall->class instanceof Name || ! $staticCall->name instanceof Identifier) {
            return;
        }

        $className  = $staticCall->class->toLowerString();
        $methodName = $staticCall->name->toLowerString();

        if ($className === 'parent') {
            if ($this->activeMethodAnalyses === []) {
                return;
            }

            $methodAnalysis = end($this->activeMethodAnalyses);

            if (! $methodAnalysis->isStatic && $methodName === $methodAnalysis->name) {
                $this->markThisUsage();
            }

            return;
        }

        $classLikeAnalysis = $this->declaringClassLikeAnalysis();

        if (($classLikeAnalysis?->methodStaticFlags[$methodName] ?? null) !== false) {
            return;
        }

        if (
            $className === 'self'
            || $className === 'static'
            || ($classLikeAnalysis->name !== null && $className === strtolower($classLikeAnalysis->name))
        ) {
            $this->markThisUsage();
        }
    }

    private function addLanguageConstruct(string $languageConstruct): void
    {
        foreach ($this->activeClassLikeAnalyses as $activeClassLikeAnalysis) {
            $activeClassLikeAnalysis->languageConstructs[$languageConstruct] = true;
        }

        $activeFunctionLikeCount = count($this->activeFunctionLikeAnalyses);

        if ($activeFunctionLikeCount > 0) {
            $this->activeFunctionLikeAnalyses[$activeFunctionLikeCount - 1]
                ->languageConstructs[$languageConstruct] = true;
        }
    }

    private function collectClassLike(ClassLike $classLike, ClassLikeAnalysis $classLikeAnalysis): void
    {
        $analysis             = $this->collectClassLikeAnalysis($classLikeAnalysis);
        $className            = $this->resolveClassName($classLike);
        [$layer, $layers]     = $this->resolveLayerData($className);
        $implements           = $this->collectImplements($classLike);
        $interfaceExtends     = $this->collectInterfaceExtends($classLike);
        [$isApi, $isInternal] = $this->detectDocBlockTags($classLike);

        $this->classNodes[] = new ClassNode(
            className:          $className,
            file:               $this->fileSyntaxAnalysis->file,
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
            traits:             $analysis['traits'],
            methods:            $analysis['methods'],
            constants:          $analysis['constants'],
            properties:         $analysis['properties'],
            functionCalls:      $analysis['functionCalls'],
            superglobals:       $analysis['superglobals'],
            languageConstructs: $analysis['languageConstructs'],
            layers:             $layers,
            isEnum:             $classLike instanceof Enum_,
            interfaceExtends:   $interfaceExtends,
            enumCases:          $analysis['enumCases'],
            enumBackingType:    $classLike instanceof Enum_ && $classLike->scalarType instanceof Identifier
                                    ? $classLike->scalarType->toLowerString()
                                    : null,
            nonClassDependencies: $analysis['nonClassDependencies'],
            constantFetches:    $analysis['constantFetches'],
            isApi:              $isApi,
            isInternal:         $isInternal,
            signatures:         $analysis['signatures'],
        );
    }

    private function collectAnonymousClass(
        Class_ $class,
        ClassLikeAnalysis $classLikeAnalysis,
        ?string $enclosingClassName,
        ?string $enclosingFunctionName,
    ): void {
        $analysis         = $this->collectClassLikeAnalysis($classLikeAnalysis);
        [$layer, $layers] = $this->resolveLayerData($enclosingClassName ?? $enclosingFunctionName ?? '');

        $this->anonymousClassNodes[] = new AnonymousClassNode(
            file:                  $this->fileSyntaxAnalysis->file,
            line:                  $class->getStartLine(),
            extends:               $class->extends instanceof Name ? $class->extends->toString() : null,
            implements:            $this->collectImplements($class),
            traits:                $analysis['traits'],
            layer:                 $layer,
            enclosingClassName:    $enclosingClassName,
            enclosingFunctionName: $enclosingFunctionName,
            hasEmptyParentheses:   AnonymousClassParentheses::emptyTokenRange(
                $this->fileSyntaxAnalysis->tokens,
                $class,
            ) !== null,
            layers:                $layers,
            isReadonly:            $class->isReadonly(),
            dependencies:          $analysis['dependencies'],
            methods:               $analysis['methods'],
            constants:             $analysis['constants'],
            properties:            $analysis['properties'],
            functionCalls:         $analysis['functionCalls'],
            superglobals:          $analysis['superglobals'],
            languageConstructs:    $analysis['languageConstructs'],
            nonClassDependencies:  $analysis['nonClassDependencies'],
        );
    }

    private function collectFunctionLike(FunctionLikeAnalysis $functionLikeAnalysis): void
    {
        $functionLike  = $functionLikeAnalysis->functionLike;
        $functionCalls = [];

        foreach ($functionLikeAnalysis->functionCallNames as $functionCallName) {
            $functionCalls[$this->resolveFunctionName($functionCallName)] = true;
        }

        $dependencies       = array_keys($functionLikeAnalysis->dependencies);
        $functionCalls      = array_keys($functionCalls);
        $superglobals       = array_keys($functionLikeAnalysis->superglobals);
        $languageConstructs = array_keys($functionLikeAnalysis->languageConstructs);
        $hasReturnType      = $functionLike->getReturnType() instanceof Node;
        $paramCount         = count($functionLike->getParams());
        $lineCount          = $this->calculateLineCount($functionLike);

        if ($functionLike instanceof Function_) {
            $functionName     = $this->resolveFunctionDeclarationName($functionLike);
            [$layer, $layers] = $this->resolveLayerData($functionName);

            $this->functionNodes[] = new FunctionNode(
                functionName:         $functionName,
                file:                 $this->fileSyntaxAnalysis->file,
                line:                 $functionLike->getStartLine(),
                layer:                $layer,
                hasReturnType:        $hasReturnType,
                paramCount:           $paramCount,
                cyclomaticComplexity: $functionLikeAnalysis->cyclomaticComplexity,
                lineCount:            $lineCount,
                dependencies:         $dependencies,
                functionCalls:        $functionCalls,
                superglobals:         $superglobals,
                languageConstructs:   $languageConstructs,
                layers:               $layers,
                isConditional: ! isset($this->fileReferenceAnalysis->declaredFunctions[strtolower($functionName)]),
            );

            return;
        }

        // The layer of an anonymous function is resolved by its file and, for
        // class-name pattern layers, by the named scope declaring it.
        $scopeName        = $functionLikeAnalysis->enclosingClassName
            ?? $functionLikeAnalysis->enclosingFunctionName
            ?? '';
        [$layer, $layers] = $this->resolveLayerData($scopeName);

        $this->anonymousFunctionNodes[] = new AnonymousFunctionNode(
            file:                  $this->fileSyntaxAnalysis->file,
            line:                  $functionLike->getStartLine(),
            layer:                 $layer,
            isArrowFunction:       $functionLike instanceof ArrowFunction,
            isStatic:              ($functionLike instanceof Closure || $functionLike instanceof ArrowFunction)
                                       && $functionLike->static,
            enclosingClassName:    $functionLikeAnalysis->enclosingClassName,
            enclosingFunctionName: $functionLikeAnalysis->enclosingFunctionName,
            usesThis:              $functionLikeAnalysis->usesThis,
            hasReturnType:         $hasReturnType,
            paramCount:            $paramCount,
            cyclomaticComplexity:  $functionLikeAnalysis->cyclomaticComplexity,
            lineCount:             $lineCount,
            dependencies:          $dependencies,
            functionCalls:         $functionCalls,
            superglobals:          $superglobals,
            languageConstructs:    $languageConstructs,
            layers:                $layers,
            requiresObjectBinding: $functionLikeAnalysis->requiresObjectBinding,
        );
    }

    /**
     * Resolve both layer representations for a scope. With zero or one match,
     * resolveAll() already determines the primary layer; the separate
     * resolve() pass is only needed for overlapping layer matches. Repeated
     * lookups are cached by ChainLayerResolver.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function resolveLayerData(string $scopeName): array
    {
        $layers = $this->layerResolver->resolveAll($scopeName, $this->fileSyntaxAnalysis->file);
        $layer  = match (count($layers)) {
            0       => null,
            1       => $layers[0],
            default => $this->layerResolver->resolve($scopeName, $this->fileSyntaxAnalysis->file),
        };

        return [$layer, $layers];
    }

    private function resolveFunctionDeclarationName(Function_ $function): string
    {
        return isset($function->namespacedName)
            ? $function->namespacedName->toString()
            : (string) $function->name;
    }

    private function resolveClassName(ClassLike $classLike): string
    {
        return isset($classLike->namespacedName)
            ? $classLike->namespacedName->toString()
            : (string) $classLike->name;
    }

    /**
     * Whether the docblock carries the `@api` and `@internal` tags.
     *
     * @return array{bool, bool} [isApi, isInternal]
     */
    private function detectDocBlockTags(ClassLike $classLike): array
    {
        $docComment = $classLike->getDocComment();

        if (! $docComment instanceof Doc) {
            return [false, false];
        }

        $text = $docComment->getText();

        return [
            preg_match(sprintf(self::DOC_BLOCK_TAG_PATTERN, 'api'), $text) === 1,
            preg_match(sprintf(self::DOC_BLOCK_TAG_PATTERN, 'internal'), $text) === 1,
        ];
    }

    /**
     * @return array{
     *     dependencies: list<string>,
     *     nonClassDependencies: list<string>,
     *     constantFetches: list<string>,
     *     signatures: list<ClassSignature>,
     *     functionCalls: string[],
     *     superglobals: string[],
     *     languageConstructs: string[],
     *     traits: string[],
     *     constants: ConstantNode[],
     *     properties: PropertyNode[],
     *     methods: MethodNode[],
     *     enumCases: EnumCaseNode[]
     * }
     */
    private function collectClassLikeAnalysis(ClassLikeAnalysis $classLikeAnalysis): array
    {
        $functionCalls = [];

        foreach ($classLikeAnalysis->functionCallNames as $functionCallName) {
            $functionCalls[$this->resolveFunctionName($functionCallName)] = true;
        }

        // Class names are case-insensitive. A normalized set avoids sorting
        // both dependency arrays and invoking a comparator for each match.
        $classDependencies    = array_change_key_case($classLikeAnalysis->classDependencies);
        $nonClassDependencies = [];

        foreach ($classLikeAnalysis->dependencies as $dependency => $isDependency) {
            if (! isset($classDependencies[strtolower($dependency)])) {
                $nonClassDependencies[] = $dependency;
            }
        }

        return [
            'dependencies'         => array_keys($classLikeAnalysis->dependencies),
            'nonClassDependencies' => $nonClassDependencies,
            'constantFetches'      => array_keys($classLikeAnalysis->constantFetches),
            'signatures'           => $classLikeAnalysis->signatures,
            'functionCalls'        => array_keys($functionCalls),
            'superglobals'         => array_keys($classLikeAnalysis->superglobals),
            'languageConstructs'   => array_keys($classLikeAnalysis->languageConstructs),
            'traits'               => $classLikeAnalysis->traits,
            'constants'            => $classLikeAnalysis->constants,
            'properties'           => $classLikeAnalysis->properties,
            'methods'              => $classLikeAnalysis->methods,
            'enumCases'            => $classLikeAnalysis->enumCases,
        ];
    }

    private function resolveFunctionName(Name $name): string
    {
        $functionName = $name->toString();

        if ($name instanceof FullyQualified) {
            if (! str_contains($functionName, '\\')) {
                $this->fileReferenceAnalysis->globalFunctionCalls[$name->toLowerString()] = $functionName;
            }

            return $functionName;
        }

        $namespacedName = $name->getAttribute('namespacedName');

        if ($namespacedName instanceof Name) {
            $namespacedNameString = $namespacedName->toString();

            // A call may be collected by its closure and enclosing scopes,
            // and repeated throughout the file. Resolve each spelling once.
            if (isset($this->fileReferenceAnalysis->resolvedFunctionNames[$namespacedNameString])) {
                return $this->fileReferenceAnalysis->resolvedFunctionNames[$namespacedNameString];
            }

            if (isset($this->fileReferenceAnalysis->declaredFunctions[strtolower($namespacedNameString)])) {
                return $this->fileReferenceAnalysis->resolvedFunctionNames[$namespacedNameString]
                    = $namespacedNameString;
            }

            // Declared in another file or not at all: which function PHP
            // calls is resolved once every function is known.
            $this->addFunctionFallback($name, $namespacedName);

            $functionName = $this->fileReferenceAnalysis->resolvedFunctionNames[$namespacedNameString]
                = self::FUNCTION_FALLBACK_MARKER_PREFIX . $namespacedNameString;
        }

        return $functionName;
    }

    private function addFunctionFallback(Name $name, Name $namespacedName): void
    {
        $reference = self::FUNCTION_FALLBACK_MARKER_PREFIX . $namespacedName->toString();

        $this->fileReferenceAnalysis->references[$reference]                = true;
        $this->fileReferenceAnalysis->fallbackNames[$name->toLowerString()] = true;
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

    /**
     * Lines spanned by the body statements. An arrow function's body is its
     * single expression, which php-parser exposes as one return statement.
     */
    private function calculateLineCount(FunctionLike $functionLike): int
    {
        $stmts = $functionLike->getStmts();

        if ($stmts === null || $stmts === []) {
            return 0;
        }

        $lastIndex = count($stmts) - 1;
        return $stmts[$lastIndex]->getEndLine() - $stmts[0]->getStartLine() + 1;
    }
}
