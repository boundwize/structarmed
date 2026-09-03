<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\JsonRecastResult;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\Parser\ParseError;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function is_file;

final readonly class JsonRecastFixerProcessor
{
    /** @param NodeJsonVisitor|non-empty-list<NodeJsonVisitor> $nodeJsonVisitors */
    public function process(string $file, NodeJsonVisitor|array $nodeJsonVisitors): bool
    {
        if (! is_file($file)) {
            return false;
        }

        if ($nodeJsonVisitors instanceof NodeJsonVisitor) {
            $nodeJsonVisitors = [$nodeJsonVisitors];
        }

        $json = (string) file_get_contents($file);

        try {
            $document = JsonRecast::parse($json);
        } catch (ParseError) {
            return false;
        }

        $nodeJsonTraverser = new NodeJsonTraverser();

        foreach ($nodeJsonVisitors as $nodeJsonVisitor) {
            $nodeJsonTraverser->addVisitor($nodeJsonVisitor);
        }

        $nodeJsonTraversalResult = $nodeJsonTraverser->traverse($document);

        if (! $nodeJsonTraversalResult->node instanceof JsonDocument) {
            throw new RuntimeException('JsonRecast fixer traversal must return JsonDocument.');
        }

        $jsonRecastResult = new JsonRecastResult($nodeJsonTraversalResult->node, $nodeJsonTraversalResult->changeSet);

        $fixedJson = JsonRecast::print($jsonRecastResult);

        return $fixedJson !== $json && file_put_contents($file, $fixedJson) !== false;
    }
}
