<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\JsonRecast\Composer;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodePath\NodeJsonPathSegment;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\Composer\RemoveMissingPsr4PathVisitor;
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
}
