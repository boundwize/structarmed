<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Analyser\Visitor\ComplexityCollectorVisitor;
use Boundwize\StructArmed\Analyser\Visitor\DependencyCollectorVisitor;
use Boundwize\StructArmed\Analyser\Visitor\FunctionCallCollectorVisitor;
use Boundwize\StructArmed\Analyser\Visitor\SuperglobalCollectorVisitor;
use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use function array_flip;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function get_defined_functions;
use function implode;
use function is_string;
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
    ];

    /** @var ClassNode[] */
    private array $nodes = [];

    /** @var array<string, int>|null */
    private ?array $internalFunctions = null;

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
            $this->fileUses[] = implode('\\', $node->name->getParts());
        }

        if ($node instanceof Function_ && isset($node->namespacedName)) {
            $this->fileFunctions[] = implode('\\', $node->namespacedName->getParts());
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
        $className = $this->resolveClassName($classLike);
        $layers    = $this->layerResolver->resolveAll($className, $this->currentFile);
        $layer     = $this->layerResolver->resolve($className, $this->currentFile);

        $this->internalFunctions ??= array_flip(array_map(strtolower(...), get_defined_functions()['internal']));

        $dependencyCollectorVisitor   = new DependencyCollectorVisitor();
        $functionCallCollectorVisitor = new FunctionCallCollectorVisitor($this->fileFunctions, $this->internalFunctions ?? []);
        $superglobalCollectorVisitor  = new SuperglobalCollectorVisitor(self::SUPERGLOBALS);
        $complexityCollectorVisitor   = new ComplexityCollectorVisitor();

        $nodeTraverser = new NodeTraverser(
            $dependencyCollectorVisitor,
            $functionCallCollectorVisitor,
            $superglobalCollectorVisitor,
            $complexityCollectorVisitor
        );
        $nodeTraverser->traverse([$classLike]);

        $dependencies  = array_values(array_unique(array_merge($this->fileUses, $dependencyCollectorVisitor->names)));
        $methods       = $this->collectMethods($classLike, $complexityCollectorVisitor->complexity);
        $functionCalls = array_unique($functionCallCollectorVisitor->calls);
        $superglobals  = array_unique($superglobalCollectorVisitor->found);
        $implements    = $this->collectImplements($classLike);
        $traits        = $this->collectTraits($classLike);
        $constants     = $this->collectConstants($classLike);
        $properties    = $this->collectProperties($classLike);

        $this->nodes[] = new ClassNode(
            className:     $className,
            file:          $this->currentFile,
            line:          $classLike->getStartLine(),
            layer:         $layer,
            extends:       $classLike instanceof Class_ && $classLike->extends instanceof Name
                               ? implode('\\', $classLike->extends->getParts())
                               : null,
            isAbstract:    $classLike instanceof Class_ && $classLike->isAbstract(),
            isFinal:       $classLike instanceof Class_ && $classLike->isFinal(),
            isInterface:   $classLike instanceof Interface_,
            isReadonly:    $classLike instanceof Class_ && $classLike->isReadonly(),
            isTrait:       $classLike instanceof Trait_,
            dependencies:  $dependencies,
            implements:    $implements,
            traits:        $traits,
            methods:       $methods,
            constants:     $constants,
            properties:    $properties,
            functionCalls: $functionCalls,
            superglobals:  $superglobals,
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
            ? implode('\\', $classLike->namespacedName->getParts())
            : (string) $classLike->name;
    }

    /**
     * @return string[]
     */
    private function collectImplements(ClassLike $classLike): array
    {
        $interfaces = [];

        if ($classLike instanceof Class_ || $classLike instanceof Enum_) {
            foreach ($classLike->implements as $interface) {
                $interfaces[] = implode('\\', $interface->getParts());
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
                $traits[] = implode('\\', $trait->getParts());
            }
        }

        return $traits;
    }

    /**
     * @param array<string, int> $complexityMap
     * @return MethodNode[]
     */
    private function collectMethods(ClassLike $classLike, array $complexityMap): array
    {
        $methods = [];

        foreach ($classLike->getMethods() as $classMethod) {
            $name      = (string) $classMethod->name;
            $methods[] = new MethodNode(
                name:                 $name,
                visibility:           $this->resolveVisibilityFromFlags($classMethod->flags),
                hasReturnType:        $classMethod->returnType !== null,
                isStatic:             $classMethod->isStatic(),
                paramCount:           count($classMethod->params),
                cyclomaticComplexity: $complexityMap[$name] ?? 1,
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
