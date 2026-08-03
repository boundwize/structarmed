<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\JsonRecast\ObjectItemNode;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
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

use function array_slice;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function trim;

final class RemoveMissingPsr4PathVisitor extends NodeJsonVisitorAbstract
{
    /** @var list<string> */
    private const AUTOLOAD_SECTIONS = ['autoload', 'autoload-dev'];

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
    {
        if (! $this->isMissingPsr4Path($nodeJson, $nodeJsonPath)) {
            return null;
        }

        return NodeJsonVisitor::REMOVE_NODE;
    }

    public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|int
    {
        if (
            ! $nodeJson instanceof ObjectItemNode
            || ! $this->becameEmptyPsr4Container($nodeJson, $nodeJsonPath)
        ) {
            return null;
        }

        return NodeJsonVisitor::REMOVE_NODE;
    }

    private function isMissingPsr4Path(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): bool
    {
        if ($nodeJson instanceof ObjectItemNode) {
            return $this->isPsr4Mapping($nodeJsonPath)
                && $this->isMissingDirectory($nodeJson->value);
        }

        return $nodeJson instanceof ArrayItemNode
            && $this->isPsr4PathListItem($nodeJsonPath)
            && $this->isMissingDirectory($nodeJson->value);
    }

    private function isMissingDirectory(NodeJson $nodeJson): bool
    {
        return $nodeJson instanceof StringNode
            && ! $this->directoryExists($nodeJson->value);
    }

    private function becameEmptyPsr4Container(ObjectItemNode $objectItemNode, NodeJsonPath $nodeJsonPath): bool
    {
        $value = $objectItemNode->value;

        if ($value instanceof ArrayNode) {
            return $this->isPsr4Mapping($nodeJsonPath)
                && $this->becameEmpty($value);
        }

        if (! $value instanceof ObjectNode || ! $this->becameEmpty($value)) {
            return false;
        }

        if ($objectItemNode->key->value === 'psr-4') {
            return $this->isComposerAutoloadPath($nodeJsonPath);
        }

        return $nodeJsonPath->isRoot()
            && $this->isComposerAutoloadKey($objectItemNode->key->value);
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

    private function becameEmpty(ObjectNode|ArrayNode $nodeJson): bool
    {
        if ($nodeJson->items !== []) {
            return false;
        }

        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        if (! is_string($originalText)) {
            return false;
        }

        $decodedValue = json_decode($originalText, true);

        return is_array($decodedValue) && $decodedValue !== [];
    }

    private function parentPath(NodeJsonPath $nodeJsonPath): NodeJsonPath
    {
        return new NodeJsonPath(array_slice($nodeJsonPath->segments(), 0, -1));
    }
}
