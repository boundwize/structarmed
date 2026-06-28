<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast\ObjectItemNode;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\StructArmed\Util\Path;

use function array_key_exists;
use function array_slice;
use function in_array;
use function is_dir;
use function strlen;
use function trim;

final class RemoveMissingPsr4PathVisitor extends NodeJsonVisitorAbstract
{
    private const CHILD_CHANGED = 'child_changed';

    /** @var list<string> */
    private const AUTOLOAD_SECTIONS = ['autoload', 'autoload-dev'];

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
        return $objectItemNode->key->value === 'psr-4'
            && $this->isComposerAutoloadPath($nodeJsonPath);
    }

    private function isEmptyComposerAutoloadItem(ObjectItemNode $objectItemNode, NodeJsonPath $nodeJsonPath): bool
    {
        return $nodeJsonPath->isRoot()
            && $this->isComposerAutoloadKey($objectItemNode->key->value)
            && $objectItemNode->value instanceof ObjectNode
            && $objectItemNode->value->items === []
            && $this->hasChangedChild($objectItemNode->value);
    }

    private function isPsr4Mapping(NodeJsonPath $nodeJsonPath): bool
    {
        return $nodeJsonPath->matches(['autoload', 'psr-4'])
            || $nodeJsonPath->matches(['autoload-dev', 'psr-4']);
    }

    private function isPsr4PathListItem(NodeJsonPath $nodeJsonPath): bool
    {
        $last = $nodeJsonPath->last();

        return $last?->isArrayIndex() === true
            && $this->isPsr4MappingValuePath($this->parentPath($nodeJsonPath));
    }

    private function isPsr4MappingValuePath(NodeJsonPath $nodeJsonPath): bool
    {
        $last = $nodeJsonPath->last();

        return $last?->isObjectKey() === true
            && $this->isPsr4Mapping($this->parentPath($nodeJsonPath));
    }

    private function isComposerAutoloadPath(NodeJsonPath $nodeJsonPath): bool
    {
        return $nodeJsonPath->matches(['autoload'])
            || $nodeJsonPath->matches(['autoload-dev']);
    }

    private function isComposerAutoloadKey(string $key): bool
    {
        return in_array($key, self::AUTOLOAD_SECTIONS, true);
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
