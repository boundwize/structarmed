<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ClassConst;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst\ChangeProtectedConstantToPrivateVisitor;
use PhpParser\Modifiers;
use PhpParser\Node\Const_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeProtectedConstantToPrivateVisitor::class)]
final class ChangeProtectedConstantToPrivateVisitorTest extends TestCase
{
    public function testChangesProtectedConstantToPrivateKeepingOtherModifiers(): void
    {
        $flags                                   = Modifiers::PROTECTED | Modifiers::FINAL;
        $classConst                              = $this->makeClassConst('Grey', $flags);
        $enum                                    = new Enum_('Status', ['stmts' => [$classConst]]);
        $changeProtectedConstantToPrivateVisitor = new ChangeProtectedConstantToPrivateVisitor('App\\Status', 'Grey');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedConstantToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PRIVATE | Modifiers::FINAL, $classConst->flags);
    }

    public function testDoesNotChangeConstantInNonEnumClassLike(): void
    {
        $classConst                              = $this->makeClassConst('Grey', Modifiers::PROTECTED);
        $class                                   = new Class_('Status', ['stmts' => [$classConst]]);
        $changeProtectedConstantToPrivateVisitor = new ChangeProtectedConstantToPrivateVisitor('App\\Status', 'Grey');

        $class->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedConstantToPrivateVisitor))->traverse([$class]);

        $this->assertSame(Modifiers::PROTECTED, $classConst->flags);
    }

    public function testDoesNotChangeConstantInDifferentEnum(): void
    {
        $classConst                              = $this->makeClassConst('Grey', Modifiers::PROTECTED);
        $enum                                    = new Enum_('Suit', ['stmts' => [$classConst]]);
        $changeProtectedConstantToPrivateVisitor = new ChangeProtectedConstantToPrivateVisitor('App\\Status', 'Grey');

        $enum->namespacedName = new Name('App\\Suit');

        (new NodeTraverser($changeProtectedConstantToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PROTECTED, $classConst->flags);
    }

    public function testDoesNotChangeDifferentConstant(): void
    {
        $classConst                              = $this->makeClassConst('Blue', Modifiers::PROTECTED);
        $enum                                    = new Enum_('Status', ['stmts' => [$classConst]]);
        $changeProtectedConstantToPrivateVisitor = new ChangeProtectedConstantToPrivateVisitor('App\\Status', 'Grey');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedConstantToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PROTECTED, $classConst->flags);
    }

    public function testDoesNotChangeNonProtectedConstant(): void
    {
        $classConst                              = $this->makeClassConst('Grey', Modifiers::PRIVATE);
        $enum                                    = new Enum_('Status', ['stmts' => [$classConst]]);
        $changeProtectedConstantToPrivateVisitor = new ChangeProtectedConstantToPrivateVisitor('App\\Status', 'Grey');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedConstantToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PRIVATE, $classConst->flags);
    }

    private function makeClassConst(string $constantName, int $flags): ClassConst
    {
        return new ClassConst([new Const_($constantName, new Int_(1))], $flags);
    }
}
