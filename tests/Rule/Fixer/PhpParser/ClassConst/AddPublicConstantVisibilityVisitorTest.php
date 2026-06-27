<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassConst;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst\AddPublicConstantVisibilityVisitor;
use PhpParser\Modifiers;
use PhpParser\Node\Const_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddPublicConstantVisibilityVisitor::class)]
final class AddPublicConstantVisibilityVisitorTest extends TestCase
{
    public function testAddsPublicVisibilityToMatchingNamespacedConstant(): void
    {
        $classConst                         = new ClassConst([new Const_('VERSION', new Int_(1))], Modifiers::FINAL);
        $class                              = new Class_('Order', ['stmts' => [$classConst]]);
        $addPublicConstantVisibilityVisitor = new AddPublicConstantVisibilityVisitor('App\\Order', 'VERSION');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicConstantVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC | Modifiers::FINAL, $classConst->flags);
    }

    public function testDoesNotChangeAlreadyVisibleConstant(): void
    {
        $classConst                         = new ClassConst([new Const_('VERSION', new Int_(1))], Modifiers::PUBLIC);
        $class                              = new Class_('Order', ['stmts' => [$classConst]]);
        $addPublicConstantVisibilityVisitor = new AddPublicConstantVisibilityVisitor('Order', 'VERSION');
        $class->namespacedName              = new Name('Order');

        (new NodeTraverser($addPublicConstantVisibilityVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC, $classConst->flags);
    }

    public function testDoesNotChangeConstantInDifferentClass(): void
    {
        $classConst                         = new ClassConst([new Const_('VERSION', new Int_(1))]);
        $class                              = new Class_('Order', ['stmts' => [$classConst]]);
        $addPublicConstantVisibilityVisitor = new AddPublicConstantVisibilityVisitor('App\\Order', 'VERSION');
        $class->namespacedName              = new Name('App\\Invoice');

        (new NodeTraverser($addPublicConstantVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $classConst->flags);
    }

    public function testDoesNotChangeDifferentConstant(): void
    {
        $classConst                         = new ClassConst([new Const_('STATUS', new Int_(1))]);
        $class                              = new Class_('Order', ['stmts' => [$classConst]]);
        $addPublicConstantVisibilityVisitor = new AddPublicConstantVisibilityVisitor('App\\Order', 'VERSION');
        $class->namespacedName              = new Name('App\\Order');

        (new NodeTraverser($addPublicConstantVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $classConst->flags);
    }

    public function testDoesNotChangeAnonymousClassConstant(): void
    {
        $classConst                         = new ClassConst([new Const_('VERSION', new Int_(1))]);
        $class                              = new Class_(null, ['stmts' => [$classConst]]);
        $addPublicConstantVisibilityVisitor = new AddPublicConstantVisibilityVisitor('App\\Missing', 'VERSION');

        (new NodeTraverser(new NameResolver(), $addPublicConstantVisibilityVisitor))->traverse([$class]);

        $this->assertSame(0, $classConst->flags);
    }
}
