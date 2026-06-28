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

use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function is_dir;
use function strlen;
use function trim;

final class RemoveMissingPsr4PathVisitor extends NodeJsonVisitorAbstract
{
    private const CHILD_CHANGED = 'child_changed';

    /** @var array<string, true> */
    private array $changedContainerPathKeys = [];

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
                $this->markContainerChanged($nodeJsonPath);

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

        $this->markContainerChanged($this->parentPath($nodeJsonPath));

        return NodeJsonVisitor::REMOVE_NODE;
    }

    public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|int
    {
        if ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode) {
            $this->flagChangedContainer($nodeJson, $nodeJsonPath);

            return null;
        }

        if (! $nodeJson instanceof ObjectItemNode) {
            return null;
        }

        if ($this->isPsr4Section($nodeJson, $nodeJsonPath)) {
            if (
                ! $nodeJson->value instanceof ObjectNode
                || $nodeJson->value->items !== []
                || ! $this->hasChangedChild($nodeJson->value)
            ) {
                return null;
            }

            $this->markContainerChanged($nodeJsonPath);

            return NodeJsonVisitor::REMOVE_NODE;
        }

        if ($this->isEmptyComposerAutoloadItem($nodeJson, $nodeJsonPath)) {
            return NodeJsonVisitor::REMOVE_NODE;
        }

        if (! $this->isPsr4Mapping($nodeJsonPath)) {
            return null;
        }

        if (
            ! $nodeJson->value instanceof ArrayNode
            || $nodeJson->value->items !== []
            || ! $this->hasChangedChild($nodeJson->value)
        ) {
            return null;
        }

        $this->markContainerChanged($nodeJsonPath);

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
            && $objectItemNode->value->items === []
            && $this->hasChangedChild($objectItemNode->value);
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

    private function markContainerChanged(NodeJsonPath $nodeJsonPath): void
    {
        $this->changedContainerPathKeys[$this->pathKey($nodeJsonPath)] = true;
    }

    private function flagChangedContainer(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): void
    {
        if (! array_key_exists($this->pathKey($nodeJsonPath), $this->changedContainerPathKeys)) {
            return;
        }

        $nodeJson->setAttribute(self::CHILD_CHANGED, true);
    }

    private function hasChangedChild(NodeJson $nodeJson): bool
    {
        return $nodeJson->getAttribute(self::CHILD_CHANGED) === true;
    }

    private function parentPath(NodeJsonPath $nodeJsonPath): NodeJsonPath
    {
        return new NodeJsonPath(array_slice($nodeJsonPath->segments(), 0, -1));
    }

    private function pathKey(NodeJsonPath $nodeJsonPath): string
    {
        $key = '';

        foreach ($nodeJsonPath->segments() as $nodeJsonPathSegment) {
            $value = (string) $nodeJsonPathSegment->value;
            $key  .= ($nodeJsonPathSegment->isObjectKey() ? 'o' : 'a') . strlen($value) . ':' . $value;
        }

        return $key;
    }
}
