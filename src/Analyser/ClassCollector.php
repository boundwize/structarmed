<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;

final class ClassCollector extends NodeVisitorAbstract
{
    private const SUPERGLOBALS = [
        '_GET', '_POST', '_REQUEST', '_SESSION',
        '_COOKIE', '_SERVER', '_ENV', '_FILES',
    ];

    /** @var ClassNode[] */
    private array $nodes = [];

    private string $currentFile = '';

    /** @var string[] */
    private array $fileUses = [];

    public function __construct(
        private readonly LayerResolverInterface $layerResolver
    ) {}

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->fileUses    = [];
    }

    /** @return ClassNode[] */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\UseUse) {
            $this->fileUses[] = implode('\\', $node->name->getParts());
            return null;
        }

        if (! $node instanceof Class_ && ! $node instanceof Interface_) {
            return null;
        }

        if ($node->name === null) {
            return null;
        }

        $className    = $this->resolveClassName($node);
        $layer        = $this->layerResolver->resolve($className, $this->currentFile);
        $dependencies = $this->collectDependencies($node);
        $methods      = $this->collectMethods($node);
        $functionCalls = $this->collectFunctionCalls($node);
        $superglobals = $this->collectSuperglobals($node);
        $implements   = $this->collectImplements($node);

        $this->nodes[] = new ClassNode(
            className:     $className,
            file:          $this->currentFile,
            line:          $node->getStartLine(),
            layer:         $layer,
            extends:       $node instanceof Class_ && $node->extends !== null
                               ? implode('\\', $node->extends->getParts())
                               : null,
            isAbstract:    $node instanceof Class_ && $node->isAbstract(),
            isFinal:       $node instanceof Class_ && $node->isFinal(),
            isInterface:   $node instanceof Interface_,
            isReadonly:    $node instanceof Class_ && $node->isReadonly(),
            dependencies:  $dependencies,
            implements:    $implements,
            methods:       $methods,
            functionCalls: $functionCalls,
            superglobals:  $superglobals,
        );

        return null;
    }

    private function resolveClassName(Class_|Interface_ $node): string
    {
        return isset($node->namespacedName)
            ? implode('\\', $node->namespacedName->getParts())
            : (string) $node->name;
    }

    /**
     * @param Class_|Interface_ $node
     * @return string[]
     */
    private function collectDependencies(Class_|Interface_ $node): array
    {
        $traverser = new NodeTraverser();
        $visitor   = new class extends NodeVisitorAbstract {
            /** @var string[] */
            public array $names = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Name\FullyQualified) {
                    $this->names[] = implode('\\', $node->getParts());
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse([$node]);

        return array_unique(array_merge($this->fileUses, $visitor->names));
    }

    /**
     * @param Class_|Interface_ $node
     * @return string[]
     */
    private function collectImplements(Class_|Interface_ $node): array
    {
        $interfaces = [];

        if ($node instanceof Class_) {
            foreach ($node->implements as $interface) {
                $interfaces[] = implode('\\', $interface->getParts());
            }
        }

        return $interfaces;
    }

    /**
     * @param Class_|Interface_ $node
     * @return MethodNode[]
     */
    private function collectMethods(Class_|Interface_ $node): array
    {
        $methods = [];

        foreach ($node->getMethods() as $method) {
            $methods[] = new MethodNode(
                name:                 (string) $method->name,
                visibility:           $this->resolveVisibility($method),
                hasReturnType:        $method->returnType !== null,
                isStatic:             $method->isStatic(),
                paramCount:           count($method->params),
                cyclomaticComplexity: $this->calculateComplexity($method),
                lineCount:            ($method->getEndLine() - $method->getStartLine()) + 1,
            );
        }

        return $methods;
    }

    private function resolveVisibility(ClassMethod $method): string
    {
        if ($method->isPublic()) {
            return 'public';
        }
        if ($method->isProtected()) {
            return 'protected';
        }

        return 'private';
    }

    private function calculateComplexity(ClassMethod $method): int
    {
        // Start at 1, add 1 for each branch point
        $complexity = 1;

        $traverser = new NodeTraverser();
        $visitor   = new class extends NodeVisitorAbstract {
            public int $count = 0;

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Node\Stmt\If_
                    || $node instanceof Node\Stmt\ElseIf_
                    || $node instanceof Node\Stmt\For_
                    || $node instanceof Node\Stmt\Foreach_
                    || $node instanceof Node\Stmt\While_
                    || $node instanceof Node\Stmt\Do_
                    || $node instanceof Node\Stmt\Case_
                    || $node instanceof Node\Stmt\Catch_
                    || $node instanceof Node\Expr\Ternary
                    || $node instanceof Node\Expr\BinaryOp\BooleanAnd
                    || $node instanceof Node\Expr\BinaryOp\BooleanOr
                    || $node instanceof Node\Expr\BinaryOp\LogicalAnd
                    || $node instanceof Node\Expr\BinaryOp\LogicalOr
                    || $node instanceof Node\Expr\Match_
                    || $node instanceof Node\Stmt\Match_
                ) {
                    $this->count++;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);

        if ($method->stmts !== null) {
            $traverser->traverse($method->stmts);
        }

        return $complexity + $visitor->count;
    }

    /**
     * @param Class_|Interface_ $node
     * @return string[]
     */
    private function collectFunctionCalls(Class_|Interface_ $node): array
    {
        $traverser = new NodeTraverser();
        $visitor   = new class extends NodeVisitorAbstract {
            /** @var string[] */
            public array $calls = [];

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Node\Name
                ) {
                    $this->calls[] = (string) $node->name;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse([$node]);

        return array_unique($visitor->calls);
    }

    /**
     * @param Class_|Interface_ $node
     * @return string[]
     */
    private function collectSuperglobals(Class_|Interface_ $node): array
    {
        $traverser = new NodeTraverser();
        $visitor   = new class extends NodeVisitorAbstract {
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
        $traverser->addVisitor($visitor);
        $traverser->traverse([$node]);

        return array_unique($visitor->found);
    }
}
