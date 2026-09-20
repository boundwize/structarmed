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
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeProtectedConstantToPrivateVisitor::class)]
final class ChangeProtectedConstantToPrivateVisitorTest extends TestCase
{
    public function testChangesProtectedConstantToPrivate(): void
    {
        $classConst                              = $this->makeClassConst('Grey', Modifiers::PROTECTED);
        $enum                                    = new Enum_('Status', ['stmts' => [$classConst]]);
        $changeProtectedConstantToPrivateVisitor = new ChangeProtectedConstantToPrivateVisitor('App\\Status', 'Grey');

        $enum->namespacedName = new Name('App\\Status');

        (new NodeTraverser($changeProtectedConstantToPrivateVisitor))->traverse([$enum]);

        $this->assertSame(Modifiers::PRIVATE, $classConst->flags);
    }

    /**
     * `final private const` is a compile error: "Private constant cannot be
     * final as it is not visible to other classes".
     */
    public function testDropsFinalWhenChangingProtectedConstantToPrivate(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            enum Status
            {
                case Draft;

                final protected const Grey = 1;
            }

            PHP;

        $expected = <<<'PHP'
            <?php

            namespace App;

            enum Status
            {
                case Draft;

                private const Grey = 1;
            }

            PHP;

        $this->assertSame($expected, $this->fix($code, new ChangeProtectedConstantToPrivateVisitor(
            'App\\Status',
            'Grey'
        )));
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

    private function fix(string $code, NodeVisitor $nodeVisitor): string
    {
        $parser             = (new ParserFactory())->createForNewestSupportedVersion();
        $originalStatements = $parser->parse($code) ?? [];
        $statements         = (new NodeTraverser(new CloningVisitor()))->traverse($originalStatements);
        $statements         = (new NodeTraverser(new NameResolver(options: ['replaceNodes' => false])))
            ->traverse($statements);
        $statements         = (new NodeTraverser($nodeVisitor))->traverse($statements);

        return (new Standard())->printFormatPreserving($statements, $originalStatements, $parser->getTokens());
    }

    private function makeClassConst(string $constantName, int $flags): ClassConst
    {
        return new ClassConst([new Const_($constantName, new Int_(1))], $flags);
    }
}
