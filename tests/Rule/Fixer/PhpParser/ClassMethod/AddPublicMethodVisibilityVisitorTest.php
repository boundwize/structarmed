<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassMethod;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod\AddPublicMethodVisibilityVisitor;
use PhpParser\Modifiers;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddPublicMethodVisibilityVisitor::class)]
final class AddPublicMethodVisibilityVisitorTest extends TestCase
{
    public function testAddsPublicVisibilityToMatchingNamespacedMethod(): void
    {
        $classMethod                      = new ClassMethod('save', ['flags' => Modifiers::STATIC], ['startLine' => 7]);
        $class                            = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicMethodVisibilityVisitor = new AddPublicMethodVisibilityVisitor('App\\Order', 'save');
        $class->namespacedName            = new Name('App\\Order');

        (new NodeTraverser($addPublicMethodVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC | Modifiers::STATIC, $classMethod->flags);
    }

    public function testDoesNotChangeAlreadyVisibleMethod(): void
    {
        $classMethod                      = new ClassMethod('save', ['flags' => Modifiers::PUBLIC], ['startLine' => 7]);
        $class                            = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicMethodVisibilityVisitor = new AddPublicMethodVisibilityVisitor('Order', 'save');
        $class->namespacedName            = new Name('Order');

        (new NodeTraverser($addPublicMethodVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC, $classMethod->flags);
    }

    public function testDoesNotChangeMethodInDifferentClass(): void
    {
        $classMethod                      = new ClassMethod('save');
        $class                            = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicMethodVisibilityVisitor = new AddPublicMethodVisibilityVisitor('App\\Order', 'save');
        $class->namespacedName            = new Name('App\\Invoice');

        (new NodeTraverser($addPublicMethodVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $classMethod->flags);
    }

    public function testDoesNotChangeDifferentMethod(): void
    {
        $classMethod                      = new ClassMethod('run', [], ['startLine' => 7]);
        $class                            = new Class_('Order', ['stmts' => [$classMethod]]);
        $addPublicMethodVisibilityVisitor = new AddPublicMethodVisibilityVisitor('App\\Order', 'save');
        $class->namespacedName            = new Name('App\\Order');

        (new NodeTraverser($addPublicMethodVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $classMethod->flags);
    }

    public function testDoesNotChangeAnonymousClassMethod(): void
    {
        $classMethod                      = new ClassMethod('run', [], ['startLine' => 7]);
        $class                            = new Class_(null, ['stmts' => [$classMethod]]);
        $addPublicMethodVisibilityVisitor = new AddPublicMethodVisibilityVisitor('App\\Missing', 'run');

        (new NodeTraverser(new NameResolver(), $addPublicMethodVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $classMethod->flags);
    }
}
