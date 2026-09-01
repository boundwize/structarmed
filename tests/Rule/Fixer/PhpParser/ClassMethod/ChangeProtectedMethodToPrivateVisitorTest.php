<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassMethod;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod\ChangeProtectedMethodToPrivateVisitor;
use PhpParser\Modifiers;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeProtectedMethodToPrivateVisitor::class)]
final class ChangeProtectedMethodToPrivateVisitorTest extends TestCase
{
    public function testChangesProtectedMethodToPrivateKeepingOtherModifiers(): void
    {
        $flags                                 = Modifiers::PROTECTED | Modifiers::STATIC;
        $classMethod                           = new ClassMethod('color', ['flags' => $flags]);
        $enum                                  = new Enum_('Status', ['stmts' => [$classMethod]]);
        $changeProtectedMethodToPrivateVisitor = new ChangeProtectedMethodToPrivateVisitor('App\\Status', 'color');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedMethodToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PRIVATE | Modifiers::STATIC, $classMethod->flags);
    }

    public function testDoesNotChangeMethodInNonEnumClassLike(): void
    {
        $classMethod                           = new ClassMethod('color', ['flags' => Modifiers::PROTECTED]);
        $class                                 = new Class_('Status', ['stmts' => [$classMethod]]);
        $changeProtectedMethodToPrivateVisitor = new ChangeProtectedMethodToPrivateVisitor('App\\Status', 'color');

        $class->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedMethodToPrivateVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PROTECTED, $classMethod->flags);
    }

    public function testDoesNotChangeMethodInDifferentEnum(): void
    {
        $classMethod                           = new ClassMethod('color', ['flags' => Modifiers::PROTECTED]);
        $enum                                  = new Enum_('Suit', ['stmts' => [$classMethod]]);
        $changeProtectedMethodToPrivateVisitor = new ChangeProtectedMethodToPrivateVisitor('App\\Status', 'color');

        $enum->namespacedName = new Name('App\\Suit');

        (new NodeTraverser($changeProtectedMethodToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PROTECTED, $classMethod->flags);
    }

    public function testDoesNotChangeDifferentMethod(): void
    {
        $classMethod                           = new ClassMethod('label', ['flags' => Modifiers::PROTECTED]);
        $enum                                  = new Enum_('Status', ['stmts' => [$classMethod]]);
        $changeProtectedMethodToPrivateVisitor = new ChangeProtectedMethodToPrivateVisitor('App\\Status', 'color');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedMethodToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PROTECTED, $classMethod->flags);
    }

    public function testDoesNotChangeNonProtectedMethod(): void
    {
        $classMethod                           = new ClassMethod('color', ['flags' => Modifiers::PRIVATE]);
        $enum                                  = new Enum_('Status', ['stmts' => [$classMethod]]);
        $changeProtectedMethodToPrivateVisitor = new ChangeProtectedMethodToPrivateVisitor('App\\Status', 'color');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedMethodToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PRIVATE, $classMethod->flags);
    }
}
