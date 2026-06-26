<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\Class_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddFinalClassVisitor;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddFinalClassVisitor::class)]
final class AddFinalClassVisitorTest extends TestCase
{
    public function testAddsFinalToMatchingNamespacedClass(): void
    {
        $class                 = new Class_('Order');
        $addFinalClassVisitor  = new AddFinalClassVisitor('App\\Order');
        $class->namespacedName = new Name('App\\Order');

        (new NodeTraverser($addFinalClassVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::FINAL, $class->flags);
    }

    public function testDoesNotChangeNonClassNode(): void
    {
        $addFinalClassVisitor = new AddFinalClassVisitor('App\\Order');

        $this->assertNotInstanceOf(Node::class, $addFinalClassVisitor->enterNode(new ClassMethod('save')));
    }

    public function testDoesNotChangeAlreadyFinalClass(): void
    {
        $class                 = new Class_('Order', ['flags' => Modifiers::FINAL]);
        $addFinalClassVisitor  = new AddFinalClassVisitor('App\\Order');
        $class->namespacedName = new Name('App\\Order');

        (new NodeTraverser($addFinalClassVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::FINAL, $class->flags);
    }

    public function testDoesNotChangeAbstractClass(): void
    {
        $class                 = new Class_('Order', ['flags' => Modifiers::ABSTRACT]);
        $addFinalClassVisitor  = new AddFinalClassVisitor('App\\Order');
        $class->namespacedName = new Name('App\\Order');

        (new NodeTraverser($addFinalClassVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::ABSTRACT, $class->flags);
    }

    public function testDoesNotChangeDifferentClass(): void
    {
        $class                 = new Class_('Order');
        $addFinalClassVisitor  = new AddFinalClassVisitor('App\\Order');
        $class->namespacedName = new Name('App\\Invoice');

        (new NodeTraverser($addFinalClassVisitor))->traverse([$class]);

        $this->assertSame(0, $class->flags);
    }

    public function testDoesNotChangeAnonymousClass(): void
    {
        $class                = new Class_(null);
        $addFinalClassVisitor = new AddFinalClassVisitor('App\\Order');

        (new NodeTraverser($addFinalClassVisitor))->traverse([$class]);

        $this->assertSame(0, $class->flags);
    }
}
