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
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use function array_merge;
use function array_unique;
use function count;
use function get_defined_functions;
use function implode;
use function in_array;
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
        $className     = $this->resolveClassName($classLike);
        $layers        = $this->layerResolver->resolveAll($className, $this->currentFile);
        $layer         = $this->layerResolver->resolve($className, $this->currentFile);
        $dependencies  = $this->collectDependencies($classLike);
        $methods       = $this->collectMethods($classLike);
        $functionCalls = $this->collectFunctionCalls($classLike);
        $superglobals  = $this->collectSuperglobals($classLike);
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
            dependencies:  $dependencies,
            implements:    $implements,
            traits:        $traits,
            methods:       $methods,
            constants:     $constants,
            properties:    $properties,
            functionCalls: $functionCalls,
            superglobals:  $superglobals,
            layers:        $layers,
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
    private function collectDependencies(ClassLike $classLike): array
    {
        $nodeTraverser = new NodeTraverser();
        $visitor       = new class extends NodeVisitorAbstract {
            /** @var string[] */
            public array $names = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof FullyQualified) {
                    $this->names[] = implode('\\', $node->getParts());
                }

                return null;
            }
        };

        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse([$classLike]);

        return array_unique(array_merge($this->fileUses, $visitor->names));
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
     * @return MethodNode[]
     */
    private function collectMethods(ClassLike $classLike): array
    {
        $methods = [];

        foreach ($classLike->getMethods() as $classMethod) {
            $methods[] = new MethodNode(
                name:                 (string) $classMethod->name,
                visibility:           $this->resolveVisibilityFromFlags($classMethod->flags),
                hasReturnType:        $classMethod->returnType !== null,
                isStatic:             $classMethod->isStatic(),
                paramCount:           count($classMethod->params),
                cyclomaticComplexity: $this->calculateComplexity($classMethod),
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

    private function calculateComplexity(ClassMethod $classMethod): int
    {
        // Start at 1, add 1 for each branch point
        $complexity = 1;

        $nodeTraverser = new NodeTraverser();
        $visitor       = new class extends NodeVisitorAbstract {
            public int $count = 0;

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof If_
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
                    || $node instanceof Match_
                ) {
                    $this->count++;
                }

                return null;
            }
        };

        $nodeTraverser->addVisitor($visitor);

        if ($classMethod->stmts !== null) {
            $nodeTraverser->traverse($classMethod->stmts);
        }

        return $complexity + $visitor->count;
    }

    /**
     * @return string[]
     */
    private function collectFunctionCalls(ClassLike $classLike): array
    {
        $nodeTraverser = new NodeTraverser();
        $visitor       = new class ($this->fileFunctions, $this->internalFunctions()) extends NodeVisitorAbstract {
            /** @var string[] */
            public array $calls = [];

            /**
             * @param string[] $fileFunctions
             * @param string[] $internalFunctions
             */
            public function __construct(
                private readonly array $fileFunctions,
                private readonly array $internalFunctions,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                ) {
                    $this->calls[] = $this->resolveFunctionName($node->name);
                }

                return null;
            }

            private function resolveFunctionName(Name $name): string
            {
                if ($name instanceof FullyQualified) {
                    return implode('\\', $name->getParts());
                }

                $functionName = implode('\\', $name->getParts());
                if (in_array(strtolower($functionName), $this->internalFunctions, true)) {
                    return $functionName;
                }

                $namespacedName = $name->getAttribute('namespacedName');
                if (
                    $namespacedName instanceof Name
                    && in_array(implode('\\', $namespacedName->getParts()), $this->fileFunctions, true)
                ) {
                    return implode('\\', $namespacedName->getParts());
                }

                return $functionName;
            }
        };

        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse([$classLike]);

        return array_unique($visitor->calls);
    }

    /**
     * @return string[]
     */
    private function internalFunctions(): array
    {
        $functions = get_defined_functions();
        $internal  = [];

        foreach ($functions['internal'] as $function) {
            $internal[] = strtolower($function);
        }

        return $internal;
    }

    /**
     * @return string[]
     */
    private function collectSuperglobals(ClassLike $classLike): array
    {
        $nodeTraverser = new NodeTraverser();
        $visitor       = new class extends NodeVisitorAbstract {
            /** @var string[] */
            public array $found = [];

            /** @var string[] */
            public array $superglobals = [];

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Variable
                    && is_string($node->name)
                    && in_array($node->name, $this->superglobals, true)
                ) {
                    $this->found[] = '$' . $node->name;
                }

                return null;
            }
        };

        $visitor->superglobals = self::SUPERGLOBALS;

        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse([$classLike]);

        return array_unique($visitor->found);
    }
}
