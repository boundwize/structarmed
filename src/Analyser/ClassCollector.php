<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
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
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use function array_merge;
use function array_unique;
use function count;
use function implode;
use function in_array;
use function is_string;

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

    public function __construct(
        private readonly LayerResolverInterface $layerResolver
    ) {
    }

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
        if ($node instanceof UseUse) {
            $this->fileUses[] = implode('\\', $node->name->getParts());
            return null;
        }

        if (! $node instanceof ClassLike) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
            return null;
        }

        $className     = $this->resolveClassName($node);
        $layer         = $this->layerResolver->resolve($className, $this->currentFile);
        $dependencies  = $this->collectDependencies($node);
        $methods       = $this->collectMethods($node);
        $functionCalls = $this->collectFunctionCalls($node);
        $superglobals  = $this->collectSuperglobals($node);
        $implements    = $this->collectImplements($node);

        $this->nodes[] = new ClassNode(
            className:     $className,
            file:          $this->currentFile,
            line:          $node->getStartLine(),
            layer:         $layer,
            extends:       $node instanceof Class_ && $node->extends instanceof Name
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

    private function resolveClassName(ClassLike $node): string
    {
        return isset($node->namespacedName)
            ? implode('\\', $node->namespacedName->getParts())
            : (string) $node->name;
    }

    /**
     * @return string[]
     */
    private function collectDependencies(ClassLike $node): array
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
        $nodeTraverser->traverse([$node]);

        return array_unique(array_merge($this->fileUses, $visitor->names));
    }

    /**
     * @return string[]
     */
    private function collectImplements(ClassLike $node): array
    {
        $interfaces = [];

        if ($node instanceof Class_ || $node instanceof Enum_) {
            foreach ($node->implements as $interface) {
                $interfaces[] = implode('\\', $interface->getParts());
            }
        }

        return $interfaces;
    }

    /**
     * @return MethodNode[]
     */
    private function collectMethods(ClassLike $node): array
    {
        $methods = [];

        foreach ($node->getMethods() as $classMethod) {
            $methods[] = new MethodNode(
                name:                 (string) $classMethod->name,
                visibility:           $this->resolveVisibility($classMethod),
                hasReturnType:        $classMethod->returnType !== null,
                isStatic:             $classMethod->isStatic(),
                paramCount:           count($classMethod->params),
                cyclomaticComplexity: $this->calculateComplexity($classMethod),
                lineCount:            ($classMethod->getEndLine() - $classMethod->getStartLine()) + 1,
                line:                 $classMethod->getStartLine(),
            );
        }

        return $methods;
    }

    private function resolveVisibility(ClassMethod $classMethod): string
    {
        if ($classMethod->isPublic()) {
            return 'public';
        }

        if ($classMethod->isProtected()) {
            return 'protected';
        }

        return 'private';
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
    private function collectFunctionCalls(ClassLike $node): array
    {
        $nodeTraverser = new NodeTraverser();
        $visitor       = new class extends NodeVisitorAbstract {
            /** @var string[] */
            public array $calls = [];

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                ) {
                    $this->calls[] = (string) $node->name;
                }

                return null;
            }
        };

        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse([$node]);

        return array_unique($visitor->calls);
    }

    /**
     * @return string[]
     */
    private function collectSuperglobals(ClassLike $node): array
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
        $nodeTraverser->traverse([$node]);

        return array_unique($visitor->found);
    }
}
