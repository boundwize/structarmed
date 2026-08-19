<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddAbstractClassVisitor;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddAbstractClassVisitor::class)]
final class AddAbstractClassVisitorTest extends TestCase
{
    public function testAddsAbstractToMatchingNamespacedClass(): void
    {
        $class                   = new Class_('BaseRepository');
        $addAbstractClassVisitor = new AddAbstractClassVisitor('App\\BaseRepository');
        $class->namespacedName   = new Name('App\\BaseRepository');

        (new NodeTraverser($addAbstractClassVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::ABSTRACT, $class->flags);
    }

    public function testDoesNotChangeNonClassNode(): void
    {
        $addAbstractClassVisitor = new AddAbstractClassVisitor('App\\BaseRepository');

        $this->assertNotInstanceOf(Node::class, $addAbstractClassVisitor->enterNode(new ClassMethod('save')));
    }

    public function testDoesNotChangeAlreadyAbstractClass(): void
    {
        $class                   = new Class_('BaseRepository', ['flags' => Modifiers::ABSTRACT]);
        $addAbstractClassVisitor = new AddAbstractClassVisitor('App\\BaseRepository');
        $class->namespacedName   = new Name('App\\BaseRepository');

        (new NodeTraverser($addAbstractClassVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::ABSTRACT, $class->flags);
    }

    public function testDoesNotChangeFinalClass(): void
    {
        $class                   = new Class_('BaseRepository', ['flags' => Modifiers::FINAL]);
        $addAbstractClassVisitor = new AddAbstractClassVisitor('App\\BaseRepository');
        $class->namespacedName   = new Name('App\\BaseRepository');

        (new NodeTraverser($addAbstractClassVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::FINAL, $class->flags);
    }

    public function testDoesNotChangeDifferentClass(): void
    {
        $class                   = new Class_('BaseRepository');
        $addAbstractClassVisitor = new AddAbstractClassVisitor('App\\BaseRepository');
        $class->namespacedName   = new Name('App\\OtherRepository');

        (new NodeTraverser($addAbstractClassVisitor))->traverse([$class]);

        $this->assertSame(0, $class->flags);
    }

    public function testDoesNotChangeAnonymousClass(): void
    {
        $class                   = new Class_(null);
        $addAbstractClassVisitor = new AddAbstractClassVisitor('App\\BaseRepository');

        (new NodeTraverser($addAbstractClassVisitor))->traverse([$class]);

        $this->assertSame(0, $class->flags);
    }
}
