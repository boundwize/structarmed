<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeVisitorAbstract;

use function implode;
use function in_array;
use function strtolower;

final class FunctionCallCollectorVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $calls = [];

    /**
     * @param string[] $fileFunctions
     * @param array<string, int> $internalFunctions
     */
    public function __construct(
        private readonly array $fileFunctions,
        private readonly array $internalFunctions,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
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
        if (isset($this->internalFunctions[strtolower($functionName)])) {
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
}
