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
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
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

    /**
     * `final private function` raises the compile warning "Private methods
     * cannot be final as they are never overridden by other classes".
     */
    public function testDropsFinalWhenChangingProtectedMethodToPrivate(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            enum Status
            {
                case Draft;

                final protected static function color(): string
                {
                    return 'grey';
                }
            }

            PHP;

        $expected = <<<'PHP'
            <?php

            namespace App;

            enum Status
            {
                case Draft;

                private static function color(): string
                {
                    return 'grey';
                }
            }

            PHP;

        $this->assertSame($expected, $this->fix($code, new ChangeProtectedMethodToPrivateVisitor(
            'App\\Status',
            'color'
        )));
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
}
