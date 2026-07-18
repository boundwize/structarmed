<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
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
use function in_array;
use function is_string;
use function spl_object_id;
use function strtolower;

final class ClassCollector extends NodeVisitorAbstract
{
    private const SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_SESSION',
        '_COOKIE',
        '_SERVER',
        '_ENV',
        '_FILES',
        'GLOBALS',
    ];

    /** @var list<ClassNode> */
    private array $nodes = [];

    private string $currentFile = '';

    /** @var list<string> */
    private array $currentNamespaceUses = [];

    /** @var ClassLike[] */
    private array $fileClassLikes = [];

    /** @var string[] */
    private array $fileFunctions = [];

    /** @var array<int, ClassLikeAnalysis> */
    private array $classLikeAnalysis = [];

    /** @var array<int, array<int, ClassMethod>> */
    private array $classLikeMethods = [];

    /** @var list<ClassLikeAnalysis> */
    private array $activeClassLikeAnalyses = [];

    /**
     * Stack of enclosing complexity scopes. Each entry is the spl_object_id of a
     * counted ClassMethod, or null for a nested named function whose branches must
     * not be counted. Closures, arrow functions and anonymous class methods do not
     * open a scope: their branches aggregate into the nearest enclosing method.
     *
     * @var list<int|null>
     */
    private array $complexityScopeStack = [];

    /** @var array<int, ClassLikeAnalysis> */
    private array $methodClassLikeAnalyses = [];

    public function __construct(
        private readonly LayerResolverInterface $layerResolver
    ) {
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile             = $file;
        $this->currentNamespaceUses    = [];
        $this->fileClassLikes          = [];
        $this->fileFunctions           = [];
        $this->classLikeAnalysis       = [];
        $this->classLikeMethods        = [];
        $this->activeClassLikeAnalyses = [];
        $this->complexityScopeStack    = [];
        $this->methodClassLikeAnalyses = [];
    }

    /** @return list<ClassNode> */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespaceUses = [];
        }

        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                $this->currentNamespaceUses[] = $use->name->toString();
            }
        }

        if ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();

            foreach ($node->uses as $use) {
                $this->currentNamespaceUses[] = $prefix . '\\' . $use->name->toString();
            }
        }

        if ($node instanceof Function_ && isset($node->namespacedName)) {
            $this->fileFunctions[] = $node->namespacedName->toString();
        }

        if ($node instanceof ClassLike && $node->name instanceof Identifier) {
            $this->startClassLikeAnalysis($node);
        }

        if ($node instanceof ClassMethod) {
            $this->startMethodAnalysis($node);
        }

        if ($node instanceof Function_) {
            $this->complexityScopeStack[] = null;
        }

        $this->collectNodeAnalysis($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (
            ($node instanceof ClassMethod && isset($this->methodClassLikeAnalyses[spl_object_id($node)]))
            || $node instanceof Function_
        ) {
            array_pop($this->complexityScopeStack);
        }

        if (! $node instanceof ClassLike) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
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

        $this->fileClassLikes          = [];
        $this->classLikeAnalysis       = [];
        $this->classLikeMethods        = [];
        $this->activeClassLikeAnalyses = [];
        $this->complexityScopeStack    = [];
        $this->methodClassLikeAnalyses = [];

        return null;
    }

    private function startClassLikeAnalysis(ClassLike $classLike): void
    {
        $classLikeId       = spl_object_id($classLike);
        $classLikeAnalysis = new ClassLikeAnalysis();
        $classLikeMethods  = [];

        $classLikeAnalysis->dependencies = $this->currentNamespaceUses;

        $this->classLikeAnalysis[$classLikeId] = $classLikeAnalysis;
        $this->activeClassLikeAnalyses[]       = $classLikeAnalysis;

        foreach ($classLike->getMethods() as $classMethod) {
            $methodId = spl_object_id($classMethod);

            $classLikeMethods[$methodId]              = $classMethod;
            $this->methodClassLikeAnalyses[$methodId] = $classLikeAnalysis;
        }

        $this->classLikeMethods[$classLikeId] = $classLikeMethods;
    }

    private function startMethodAnalysis(ClassMethod $classMethod): void
    {
        $methodId = spl_object_id($classMethod);

        $analysis = $this->methodClassLikeAnalyses[$methodId] ?? null;

        // Methods of an anonymous class are not collected as their own nodes, so
        // they open no scope: their branches aggregate into the enclosing method.
        if (! $analysis instanceof ClassLikeAnalysis) {
            return;
        }

        $this->complexityScopeStack[] = $methodId;

        $analysis->complexityByMethodId[$methodId] = 1;
    }

    private function collectNodeAnalysis(Node $node): void
    {
        if ($this->activeClassLikeAnalyses === []) {
            return;
        }

        if ($node instanceof FullyQualified) {
            $name = $node->toString();
            if (! in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                $this->addDependency($name);
            }
        }

        if (
            $node instanceof FuncCall
            && $node->name instanceof Name
        ) {
            $this->addFunctionCallName($node->name);
        }

        if ($node instanceof Exit_) {
            $this->addLanguageConstruct(
                $node->getAttribute('kind') === Exit_::KIND_DIE
                    ? 'die'
                    : 'exit'
            );
        }

        if ($node instanceof Echo_) {
            $this->addLanguageConstruct('echo');
        }

        if ($node instanceof Print_) {
            $this->addLanguageConstruct('print');
        }

        if ($node instanceof Include_) {
            $this->addLanguageConstruct(match ($node->type) {
                Include_::TYPE_REQUIRE      => 'require',
                Include_::TYPE_INCLUDE_ONCE => 'include_once',
                Include_::TYPE_REQUIRE_ONCE => 'require_once',
                default                     => 'include',
            });
        }

        if ($node instanceof Isset_) {
            $this->addLanguageConstruct('isset');
        }

        if ($node instanceof Empty_) {
            $this->addLanguageConstruct('empty');
        }

        if ($node instanceof Unset_) {
            $this->addLanguageConstruct('unset');
        }

        if ($node instanceof Eval_) {
            $this->addLanguageConstruct('eval');
        }

        if ($node instanceof List_) {
            $this->addLanguageConstruct('list');
        }

        if (
            $node instanceof Variable
            && is_string($node->name)
            && in_array($node->name, self::SUPERGLOBALS, true)
        ) {
            $this->addSuperglobal('$' . $node->name);
        }

        if ($this->complexityScopeStack !== [] && $this->isComplexityBranch($node)) {
            $activeMethodId = $this->complexityScopeStack[count($this->complexityScopeStack) - 1];

            if ($activeMethodId !== null) {
                $this->methodClassLikeAnalyses[$activeMethodId]->complexityByMethodId[$activeMethodId]++;
            }
        }
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
        $classLikeMethods = $this->classLikeMethods[$classLikeId];
        $methods          = $this->collectMethods($classLikeMethods, $analysis['complexityByMethodId']);
        $implements       = $this->collectImplements($classLike);
        $interfaceExtends = $this->collectInterfaceExtends($classLike);
        $traits           = $this->collectTraits($classLike);
        $constants        = $this->collectConstants($classLike);
        $properties       = $this->collectProperties($classLike, $classLikeMethods);

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
        );
    }

    /**
     * @return ConstantNode[]
     */
    private function collectConstants(ClassLike $classLike): array
    {
        $constants = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof ClassConst) {
                continue;
            }

            $visibility            = $this->resolveVisibilityFromFlags($stmt->flags);
            $hasExplicitVisibility = $this->hasExplicitVisibilityFlag($stmt->flags);

            foreach ($stmt->consts as $const) {
                $constants[] = new ConstantNode(
                    name:                 (string) $const->name,
                    visibility:           $visibility,
                    hasExplicitVisibility: $hasExplicitVisibility,
                    line:                 $const->getStartLine(),
                );
            }
        }

        return $constants;
    }

    /**
     * @param array<int, ClassMethod> $classLikeMethods
     * @return PropertyNode[]
     */
    private function collectProperties(ClassLike $classLike, array $classLikeMethods): array
    {
        $properties = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof Property) {
                continue;
            }

            $visibility            = $this->resolveVisibilityFromFlags($stmt->flags);
            $hasExplicitVisibility = $this->hasExplicitVisibilityFlag($stmt->flags);

            foreach ($stmt->props as $prop) {
                $properties[] = new PropertyNode(
                    name:                 (string) $prop->name,
                    visibility:           $visibility,
                    hasExplicitVisibility: $hasExplicitVisibility,
                    line:                 $prop->getStartLine(),
                );
            }
        }

        foreach ($classLikeMethods as $classLikeMethod) {
            if ($classLikeMethod->name->toLowerString() !== '__construct') {
                continue;
            }

            foreach ($classLikeMethod->params as $param) {
                if ($param->flags === 0 || ! $param->var instanceof Variable || ! is_string($param->var->name)) {
                    continue;
                }

                $properties[] = new PropertyNode(
                    name:                  (string) $param->var->name,
                    visibility:            $this->resolveVisibilityFromFlags($param->flags),
                    hasExplicitVisibility: $this->hasExplicitVisibilityFlag($param->flags),
                    line:                  $param->getStartLine(),
                );
            }

            // stop since __construct() already processed
            break;
        }

        return $properties;
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

        if (
            $namespacedName instanceof Name
            && in_array($namespacedName->toString(), $this->fileFunctions, true)
        ) {
            return $namespacedName->toString();
        }

        return $functionName;
    }

    private function isComplexityBranch(Node $node): bool
    {
        return $node instanceof If_
            || $node instanceof ElseIf_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Case_
            || $node instanceof Catch_
            || $node instanceof Ternary
            || $node instanceof BooleanAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalAnd
            || $node instanceof LogicalOr
            || $node instanceof Match_;
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
     * @return string[]
     */
    private function collectTraits(ClassLike $classLike): array
    {
        if ($classLike instanceof Interface_) {
            return [];
        }

        $traits = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        return $traits;
    }

    /**
     * @param array<int, ClassMethod> $classLikeMethods
     * @param array<int, int> $complexityByMethodId
     * @return MethodNode[]
     */
    private function collectMethods(array $classLikeMethods, array $complexityByMethodId): array
    {
        $methods = [];

        foreach ($classLikeMethods as $methodId => $classMethod) {
            $methods[] = new MethodNode(
                name:                 (string) $classMethod->name,
                visibility:           $this->resolveVisibilityFromFlags($classMethod->flags),
                hasReturnType:        $classMethod->returnType !== null,
                isStatic:             $classMethod->isStatic(),
                paramCount:           count($classMethod->params),
                cyclomaticComplexity: $complexityByMethodId[$methodId] ?? 1,
                lineCount:            $this->calculateMethodLineCount($classMethod),
                hasExplicitVisibility: $this->hasExplicitVisibilityFlag($classMethod->flags),
                line:                 $classMethod->getStartLine(),
            );
        }

        return $methods;
    }

    private function resolveVisibilityFromFlags(int $flags): string
    {
        if (($flags & Modifiers::PROTECTED) !== 0) {
            return 'protected';
        }

        if (($flags & Modifiers::PRIVATE) !== 0) {
            return 'private';
        }

        return 'public';
    }

    private function hasExplicitVisibilityFlag(int $flags): bool
    {
        return ($flags & Modifiers::VISIBILITY_MASK) !== 0;
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
