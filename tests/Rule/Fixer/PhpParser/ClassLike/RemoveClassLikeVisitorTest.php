<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassLike;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike\RemoveClassLikeVisitor;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoveClassLikeVisitor::class)]
final class RemoveClassLikeVisitorTest extends TestCase
{
    public function testRemovesMatchingInterface(): void
    {
        $interface                 = new Interface_('UnusedInterface');
        $interface->namespacedName = new Name('App\\UnusedInterface');

        $statements = (new NodeTraverser(new RemoveClassLikeVisitor('App\\UnusedInterface')))
            ->traverse([$interface]);

        $this->assertSame([], $statements);
    }

    public function testRemovesMatchingAbstractClass(): void
    {
        $class                 = new Class_('AbstractHandler');
        $class->namespacedName = new Name('App\\AbstractHandler');

        $statements = (new NodeTraverser(new RemoveClassLikeVisitor('App\\AbstractHandler')))
            ->traverse([$class]);

        $this->assertSame([], $statements);
    }

    public function testRemovesMatchingTrait(): void
    {
        $trait                 = new Trait_('UnusedTrait');
        $trait->namespacedName = new Name('App\\UnusedTrait');

        $statements = (new NodeTraverser(new RemoveClassLikeVisitor('App\\UnusedTrait')))
            ->traverse([$trait]);

        $this->assertSame([], $statements);
    }

    public function testKeepsNonMatchingClassLike(): void
    {
        $interface                 = new Interface_('UsedInterface');
        $interface->namespacedName = new Name('App\\UsedInterface');

        $statements = (new NodeTraverser(new RemoveClassLikeVisitor('App\\UnusedInterface')))
            ->traverse([$interface]);

        $this->assertSame([$interface], $statements);
    }

    public function testKeepsAnonymousClass(): void
    {
        $class = new Class_(null);

        $statements = (new NodeTraverser(new RemoveClassLikeVisitor('App\\UnusedInterface')))
            ->traverse([$class]);

        $this->assertSame([$class], $statements);
    }

    public function testDoesNotRemoveNonClassLikeNode(): void
    {
        $removeClassLikeVisitor = new RemoveClassLikeVisitor('App\\UnusedInterface');

        $this->assertNull($removeClassLikeVisitor->leaveNode(new ClassMethod('save')));
    }
}
