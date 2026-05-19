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
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
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
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use function array_merge;
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

    /** @var ClassNode[] */
    private array $nodes = [];

    private string $currentFile = '';

    /** @var string[] */
    private array $fileUses = [];

    /** @var ClassLike[] */
    private array $fileClassLikes = [];

    /** @var string[] */
    private array $fileFunctions = [];

    public function __construct(
        private readonly LayerResolverInterface $layerResolver
    ) {
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile    = $file;
        $this->fileUses       = [];
        $this->fileClassLikes = [];
        $this->fileFunctions  = [];
    }

    /** @return ClassNode[] */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof UseItem) {
            $this->fileUses[] = $node->name->toString();
        }

        if ($node instanceof Function_ && isset($node->namespacedName)) {
            $this->fileFunctions[] = $node->namespacedName->toString();
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof ClassLike) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
            return null;
        }

        $this->fileClassLikes[] = $node;

        return null;
    }

    /** @param Node[] $nodes */
    public function afterTraverse(array $nodes): null
    {
        foreach ($this->fileClassLikes as $fileClassLike) {
            $this->collectClassLike($fileClassLike);
        }

        $this->fileClassLikes = [];

        return null;
    }

    private function collectClassLike(ClassLike $classLike): void
    {
        $analysis   = $this->collectClassLikeAnalysis($classLike);
        $className  = $this->resolveClassName($classLike);
        $layers     = $this->layerResolver->resolveAll($className, $this->currentFile);
        $layer      = $this->layerResolver->resolve($className, $this->currentFile);
        $methods    = $this->collectMethods($classLike, $analysis['complexityByMethodId']);
        $implements = $this->collectImplements($classLike);
        $traits     = $this->collectTraits($classLike);
        $constants  = $this->collectConstants($classLike);
        $properties = $this->collectProperties($classLike);

        $this->nodes[] = new ClassNode(
            className:     $className,
            file:          $this->currentFile,
            line:          $classLike->getStartLine(),
            layer:         $layer,
            extends:       $classLike instanceof Class_ && $classLike->extends instanceof Name
                               ? $classLike->extends->toString()
                               : null,
            isAbstract:    $classLike instanceof Class_ && $classLike->isAbstract(),
            isFinal:       $classLike instanceof Class_ && $classLike->isFinal(),
            isInterface:   $classLike instanceof Interface_,
            isReadonly:    $classLike instanceof Class_ && $classLike->isReadonly(),
            isTrait:       $classLike instanceof Trait_,
            dependencies:  $analysis['dependencies'],
            implements:    $implements,
            traits:        $traits,
            methods:       $methods,
            constants:     $constants,
            properties:    $properties,
            functionCalls: $analysis['functionCalls'],
            superglobals:  $analysis['superglobals'],
            layers:        $layers,
            isEnum:        $classLike instanceof Enum_,
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
     * @return PropertyNode[]
     */
    private function collectProperties(ClassLike $classLike): array
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

        foreach ($classLike->getMethods() as $classMethod) {
            if ($classMethod->name->toLowerString() !== '__construct') {
                continue;
            }

            foreach ($classMethod->params as $param) {
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
     *     complexityByMethodId: array<int, int>
     * }
     */
    private function collectClassLikeAnalysis(ClassLike $classLike): array
    {
        $methodIds = [];

        foreach ($classLike->getMethods() as $classMethod) {
            $methodIds[spl_object_id($classMethod)] = true;
        }

        $nodeTraverser = new NodeTraverser();
        $visitor       = new class ($this->fileFunctions, $methodIds, self::SUPERGLOBALS) extends NodeVisitorAbstract {
            /** @var string[] */
            public array $dependencies = [];

            /** @var string[] */
            public array $functionCalls = [];

            /** @var string[] */
            public array $superglobals = [];

            /** @var array<int, int> */
            public array $complexityByMethodId = [];

            /** @var list<int> */
            private array $activeMethodIds = [];

            /**
             * @param string[]         $fileFunctions
             * @param array<int, true> $methodIds
             * @param string[]         $superglobalNames
             */
            public function __construct(
                private readonly array $fileFunctions,
                private readonly array $methodIds,
                private readonly array $superglobalNames,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassMethod) {
                    $methodId = spl_object_id($node);

                    if (isset($this->methodIds[$methodId])) {
                        $this->activeMethodIds[]               = $methodId;
                        $this->complexityByMethodId[$methodId] = 1;
                    }
                }

                if ($node instanceof FullyQualified) {
                    $name = $node->toString();
                    if (! in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                        $this->dependencies[] = $name;
                    }
                }

                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                ) {
                    $this->functionCalls[] = $this->resolveFunctionName($node->name);
                }

                if (
                    $node instanceof Variable
                    && is_string($node->name)
                    && in_array($node->name, $this->superglobalNames, true)
                ) {
                    $this->superglobals[] = '$' . $node->name;
                }

                if ($this->activeMethodIds !== [] && $this->isComplexityBranch($node)) {
                    foreach ($this->activeMethodIds as $activeMethodId) {
                        $this->complexityByMethodId[$activeMethodId]++;
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if (! $node instanceof ClassMethod) {
                    return null;
                }

                $methodId = spl_object_id($node);

                if (isset($this->methodIds[$methodId])) {
                    array_pop($this->activeMethodIds);
                }

                return null;
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
        };

        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse([$classLike]);

        return [
            'dependencies'         => array_values(array_unique(array_merge($this->fileUses, $visitor->dependencies))),
            'functionCalls'        => array_values(array_unique($visitor->functionCalls)),
            'superglobals'         => array_values(array_unique($visitor->superglobals)),
            'complexityByMethodId' => $visitor->complexityByMethodId,
        ];
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
    private function collectTraits(ClassLike $classLike): array
    {
        if (! $classLike instanceof Class_) {
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
     * @return MethodNode[]
     * @param array<int, int> $complexityByMethodId
     */
    private function collectMethods(ClassLike $classLike, array $complexityByMethodId): array
    {
        $methods = [];

        foreach ($classLike->getMethods() as $classMethod) {
            $methods[] = new MethodNode(
                name:                 (string) $classMethod->name,
                visibility:           $this->resolveVisibilityFromFlags($classMethod->flags),
                hasReturnType:        $classMethod->returnType !== null,
                isStatic:             $classMethod->isStatic(),
                paramCount:           count($classMethod->params),
                cyclomaticComplexity: $complexityByMethodId[spl_object_id($classMethod)] ?? 1,
                lineCount:            ($classMethod->getEndLine() - $classMethod->getStartLine()) + 1,
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
}
