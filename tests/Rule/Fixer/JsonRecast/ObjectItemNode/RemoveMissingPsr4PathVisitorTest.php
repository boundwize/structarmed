<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\JsonRecast\ObjectItemNode;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodePath\NodeJsonPathSegment;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\ObjectItemNode\RemoveMissingPsr4PathVisitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoveMissingPsr4PathVisitor::class)]
final class RemoveMissingPsr4PathVisitorTest extends TestCase
{
    public function testEnterNodeIgnoresArrayItemsOutsidePsr4PathLists(): void
    {
        $removeMissingPsr4PathVisitor = new RemoveMissingPsr4PathVisitor('.');
        $arrayItemNode                = new ArrayItemNode(new StringNode('missing'));

        $this->assertNull($removeMissingPsr4PathVisitor->enterNode($arrayItemNode, new NodeJsonPath([
            NodeJsonPathSegment::objectKey('autoload'),
            NodeJsonPathSegment::objectKey('psr-4'),
            NodeJsonPathSegment::objectKey('App\\'),
        ])));
        $this->assertNull($removeMissingPsr4PathVisitor->enterNode($arrayItemNode, new NodeJsonPath([
            NodeJsonPathSegment::objectKey('scripts'),
            NodeJsonPathSegment::objectKey('psr-4'),
            NodeJsonPathSegment::objectKey('App\\'),
            NodeJsonPathSegment::arrayIndex(0),
        ])));
        $this->assertNull($removeMissingPsr4PathVisitor->enterNode($arrayItemNode, new NodeJsonPath([
            NodeJsonPathSegment::objectKey('autoload'),
            NodeJsonPathSegment::objectKey('classmap'),
            NodeJsonPathSegment::objectKey('App\\'),
            NodeJsonPathSegment::arrayIndex(0),
        ])));
    }

    public function testChangedContainerPathKeysIncludeSegmentValueWhenLengthsMatch(): void
    {
        $removeMissingPsr4PathVisitor = new RemoveMissingPsr4PathVisitor('.');
        $nodeJsonPath                 = new NodeJsonPath([
            NodeJsonPathSegment::objectKey('autoload'),
            NodeJsonPathSegment::objectKey('psr-4'),
        ]);

        $this->assertSame(NodeJsonVisitor::REMOVE_NODE, $removeMissingPsr4PathVisitor->enterNode(
            new ArrayItemNode(new StringNode('structarmed-missing-path-for-equal-length-key-test')),
            new NodeJsonPath([
                NodeJsonPathSegment::objectKey('autoload'),
                NodeJsonPathSegment::objectKey('psr-4'),
                NodeJsonPathSegment::objectKey('App\\'),
                NodeJsonPathSegment::arrayIndex(0),
            ])
        ));

        $removedAppArray = new ArrayNode([]);
        $keptFooArray    = new ArrayNode([]);

        $removeMissingPsr4PathVisitor->leaveNode($removedAppArray, new NodeJsonPath([
            NodeJsonPathSegment::objectKey('autoload'),
            NodeJsonPathSegment::objectKey('psr-4'),
            NodeJsonPathSegment::objectKey('App\\'),
        ]));

        $this->assertSame(NodeJsonVisitor::REMOVE_NODE, $removeMissingPsr4PathVisitor->leaveNode(
            new ObjectItemNode(new StringNode('App\\'), $removedAppArray),
            $nodeJsonPath
        ));

        $removeMissingPsr4PathVisitor->leaveNode($keptFooArray, new NodeJsonPath([
            NodeJsonPathSegment::objectKey('autoload'),
            NodeJsonPathSegment::objectKey('psr-4'),
            NodeJsonPathSegment::objectKey('Foo\\'),
        ]));

        $this->assertNull($removeMissingPsr4PathVisitor->leaveNode(
            new ObjectItemNode(new StringNode('Foo\\'), $keptFooArray),
            $nodeJsonPath
        ));
    }
}
