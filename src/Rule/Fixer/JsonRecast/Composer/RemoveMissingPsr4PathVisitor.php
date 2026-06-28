<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast\Composer;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodePath\NodeJsonPathSegment;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\StructArmed\Util\Path;

use function count;
use function in_array;
use function is_dir;
use function trim;

final class RemoveMissingPsr4PathVisitor extends NodeJsonVisitorAbstract
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
    {
        if ($nodeJson instanceof ObjectItemNode && $this->isPsr4Mapping($nodeJsonPath)) {
            if (
                $nodeJson->value instanceof StringNode
                && ! $this->directoryExists($nodeJson->value->value)
            ) {
                return NodeJsonVisitor::REMOVE_NODE;
            }

            return null;
        }

        if (! $nodeJson instanceof ArrayItemNode || ! $this->isPsr4PathListItem($nodeJsonPath)) {
            return null;
        }

        if (! $nodeJson->value instanceof StringNode || $this->directoryExists($nodeJson->value->value)) {
            return null;
        }

        return NodeJsonVisitor::REMOVE_NODE;
    }

    public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int
    {
        if ($nodeJson instanceof ObjectNode && $nodeJsonPath->isRoot() && $nodeJson->items === []) {
            $nodeJson->afterOpenBrace   = '';
            $nodeJson->beforeCloseBrace = '';

            return $nodeJson;
        }

        if (! $nodeJson instanceof ObjectItemNode) {
            return null;
        }

        if ($this->isPsr4Section($nodeJson, $nodeJsonPath)) {
            if (! $nodeJson->value instanceof ObjectNode || $nodeJson->value->items !== []) {
                return null;
            }

            return NodeJsonVisitor::REMOVE_NODE;
        }

        if ($this->isEmptyComposerAutoloadItem($nodeJson, $nodeJsonPath)) {
            return NodeJsonVisitor::REMOVE_NODE;
        }

        if (! $this->isPsr4Mapping($nodeJsonPath)) {
            return null;
        }

        if (! $nodeJson->value instanceof ArrayNode || $nodeJson->value->items !== []) {
            return null;
        }

        return NodeJsonVisitor::REMOVE_NODE;
    }

    private function isPsr4Section(ObjectItemNode $objectItemNode, NodeJsonPath $nodeJsonPath): bool
    {
        $segments = $nodeJsonPath->segments();

        if (count($segments) !== 1) {
            return false;
        }

        return $this->isComposerAutoloadSection($segments[0])
            && $objectItemNode->key->value === 'psr-4';
    }

    private function isEmptyComposerAutoloadItem(ObjectItemNode $objectItemNode, NodeJsonPath $nodeJsonPath): bool
    {
        if ($nodeJsonPath->segments() !== []) {
            return false;
        }

        return in_array($objectItemNode->key->value, ['autoload', 'autoload-dev'], true)
            && $objectItemNode->value instanceof ObjectNode
            && $objectItemNode->value->items === [];
    }

    private function isPsr4Mapping(NodeJsonPath $nodeJsonPath): bool
    {
        $segments = $nodeJsonPath->segments();

        if (count($segments) !== 2) {
            return false;
        }

        return $this->isComposerAutoloadSection($segments[0])
            && $this->isObjectKey($segments[1], 'psr-4');
    }

    private function isPsr4PathListItem(NodeJsonPath $nodeJsonPath): bool
    {
        $segments = $nodeJsonPath->segments();

        if (count($segments) !== 4) {
            return false;
        }

        if (! $this->isComposerAutoloadSection($segments[0])) {
            return false;
        }

        if (! $this->isObjectKey($segments[1], 'psr-4')) {
            return false;
        }

        return $segments[2]->isObjectKey() && $segments[3]->isArrayIndex();
    }

    private function isComposerAutoloadSection(NodeJsonPathSegment $nodeJsonPathSegment): bool
    {
        return $nodeJsonPathSegment->isObjectKey()
            && in_array($nodeJsonPathSegment->value, ['autoload', 'autoload-dev'], true);
    }

    private function isObjectKey(NodeJsonPathSegment $nodeJsonPathSegment, string $key): bool
    {
        return $nodeJsonPathSegment->isObjectKey() && $nodeJsonPathSegment->value === $key;
    }

    private function directoryExists(string $path): bool
    {
        return is_dir(Path::resolve(Path::normalise(trim($path)), $this->basePath));
    }
}
