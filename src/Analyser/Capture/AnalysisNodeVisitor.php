<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Capture;

use PhpParser\Node;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Resolves names before collecting facts in the same traversal. A single
 * visitor avoids a second dispatch loop entry and NameResolver's empty
 * leaveNode() call for every AST node.
 *
 * @internal
 */
final class AnalysisNodeVisitor extends NameResolver
{
    public function __construct(private readonly AnalysisNodeCollector $collector)
    {
        parent::__construct();
    }

    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        return $this->collector->enterNode($node);
    }

    public function leaveNode(Node $node): null
    {
        return $this->collector->leaveNode($node);
    }

    /** @param Node[] $nodes */
    public function afterTraverse(array $nodes): null
    {
        return $this->collector->afterTraverse($nodes);
    }
}
