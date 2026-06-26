<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassConstant;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConstant\AddPublicConstantVisibilityVisitor;
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
        $classConstant         = new ClassConst([new Const_('VERSION', new Int_(1))], Modifiers::FINAL);
        $class                 = new Class_('Order', ['stmts' => [$classConstant]]);
        $visitor               = new AddPublicConstantVisibilityVisitor('App\\Order', 'VERSION');
        $class->namespacedName = new Name('App\\Order');

        (new NodeTraverser($visitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC | Modifiers::FINAL, $classConstant->flags);
    }

    public function testDoesNotChangeAlreadyVisibleConstant(): void
    {
        $classConstant         = new ClassConst([new Const_('VERSION', new Int_(1))], Modifiers::PUBLIC);
        $class                 = new Class_('Order', ['stmts' => [$classConstant]]);
        $visitor               = new AddPublicConstantVisibilityVisitor('Order', 'VERSION');
        $class->namespacedName = new Name('Order');

        (new NodeTraverser($visitor))->traverse([$class]);

        $this->assertSame(Modifiers::PUBLIC, $classConstant->flags);
    }

    public function testDoesNotChangeConstantInDifferentClass(): void
    {
        $classConstant         = new ClassConst([new Const_('VERSION', new Int_(1))]);
        $class                 = new Class_('Order', ['stmts' => [$classConstant]]);
        $visitor               = new AddPublicConstantVisibilityVisitor('App\\Order', 'VERSION');
        $class->namespacedName = new Name('App\\Invoice');

        (new NodeTraverser($visitor))->traverse([$class]);

        $this->assertSame(0, $classConstant->flags);
    }

    public function testDoesNotChangeDifferentConstant(): void
    {
        $classConstant         = new ClassConst([new Const_('STATUS', new Int_(1))]);
        $class                 = new Class_('Order', ['stmts' => [$classConstant]]);
        $visitor               = new AddPublicConstantVisibilityVisitor('App\\Order', 'VERSION');
        $class->namespacedName = new Name('App\\Order');

        (new NodeTraverser($visitor))->traverse([$class]);

        $this->assertSame(0, $classConstant->flags);
    }

    public function testDoesNotChangeAnonymousClassConstant(): void
    {
        $classConstant = new ClassConst([new Const_('VERSION', new Int_(1))]);
        $class         = new Class_(null, ['stmts' => [$classConstant]]);
        $visitor       = new AddPublicConstantVisibilityVisitor('App\\Missing', 'VERSION');

        (new NodeTraverser(new NameResolver(), $visitor))->traverse([$class]);

        $this->assertSame(0, $classConstant->flags);
    }
}
