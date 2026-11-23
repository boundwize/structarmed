<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Capture;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

use function is_array;

/**
 * Traverses a file with the NameResolver and the AnalysisNodeCollector, and
 * calls the collector only for the node classes it acts on: most nodes
 * (identifiers, arguments, assignments, ...) are none of them, and a call
 * that returns on its first line still costs a call, twice per node.
 *
 * Both visitors always return null, so their return values are not read:
 * this traverser is no replacement for the NodeTraverser with other visitors.
 *
 * @internal
 */
final class AnalysisNodeTraverser extends NodeTraverser
{
    public function __construct(
        private readonly NameResolver $nameResolver,
        private readonly AnalysisNodeCollector $analysisNodeCollector,
    ) {
        parent::__construct($nameResolver, $analysisNodeCollector);
    }

    protected function traverseNode(Node $node): void
    {
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            if (is_array($subNode)) {
                $this->traverseArray($subNode);

                continue;
            }

            if (! $subNode instanceof Node) {
                continue;
            }

            // Same steps as in traverseArray(): a shared method would add
            // back one call per node.
            $this->nameResolver->enterNode($subNode);

            $nodeClass = $subNode::class;

            if (isset(AnalysisNodeCollector::ENTER_NODES[$nodeClass])) {
                $this->analysisNodeCollector->enterNode($subNode);
            }

            $this->traverseNode($subNode);

            if (isset(AnalysisNodeCollector::LEAVE_NODES[$nodeClass])) {
                $this->analysisNodeCollector->leaveNode($subNode);
            }
        }
    }

    /**
     * @param array<mixed> $nodes
     * @return array<mixed> The same array: no visitor replaces or removes a node
     */
    protected function traverseArray(array $nodes): array
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Node) {
                continue;
            }

            $this->nameResolver->enterNode($node);

            $nodeClass = $node::class;

            if (isset(AnalysisNodeCollector::ENTER_NODES[$nodeClass])) {
                $this->analysisNodeCollector->enterNode($node);
            }

            $this->traverseNode($node);

            if (isset(AnalysisNodeCollector::LEAVE_NODES[$nodeClass])) {
                $this->analysisNodeCollector->leaveNode($node);
            }
        }

        return $nodes;
    }
}
