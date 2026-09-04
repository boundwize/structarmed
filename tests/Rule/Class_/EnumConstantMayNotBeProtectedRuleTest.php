<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst\ChangeProtectedConstantToPrivateVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumConstantMayNotBeProtectedRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(EnumConstantMayNotBeProtectedRule::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(ChangeProtectedConstantToPrivateVisitor::class)]
final class EnumConstantMayNotBeProtectedRuleTest extends TestCase
{
    public function testAppliesOnlyToEnumsInConfiguredLayer(): void
    {
        $enumConstantMayNotBeProtectedRule = new EnumConstantMayNotBeProtectedRule('Source');

        $this->assertTrue($enumConstantMayNotBeProtectedRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($enumConstantMayNotBeProtectedRule->appliesTo($this->makeNode([], 'Other')));
        $this->assertFalse($enumConstantMayNotBeProtectedRule->appliesTo(
            $this->makeNode([], 'Source', isEnum: false)
        ));
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $enumConstantMayNotBeProtectedRule = new EnumConstantMayNotBeProtectedRule('Source');

        $violation = $enumConstantMayNotBeProtectedRule->evaluate(
            $this->makeNode([
                new ConstantNode('Grey', 'protected', hasExplicitVisibility: true),
                new ConstantNode('Blue', 'protected', hasExplicitVisibility: true),
            ])
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(1, $violation->line);
    }

    #[DataProvider('allowedVisibilityProvider')]
    public function testPassesNonProtectedConstants(string $visibility): void
    {
        $enumConstantMayNotBeProtectedRule = new EnumConstantMayNotBeProtectedRule('Source');

        $this->assertSame(
            [],
            $enumConstantMayNotBeProtectedRule->evaluateAll(
                $this->makeNode([new ConstantNode('Grey', $visibility, hasExplicitVisibility: true)])
            )
        );
    }

    /** @return iterable<string, array{string}> */
    public static function allowedVisibilityProvider(): iterable
    {
        yield 'public'  => ['public'];
        yield 'private' => ['private'];
    }

    public function testViolatesProtectedConstants(): void
    {
        $enumConstantMayNotBeProtectedRule = new EnumConstantMayNotBeProtectedRule('Source');

        $violations = $enumConstantMayNotBeProtectedRule->evaluateAll(
            $this->makeNode([
                new ConstantNode('Grey', 'public', hasExplicitVisibility: true, line: 5),
                new ConstantNode('Blue', 'protected', hasExplicitVisibility: true, line: 9),
                new ConstantNode('Red', 'protected', hasExplicitVisibility: true, line: 13),
            ])
        );

        $this->assertCount(2, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertSame(9, $violations[0]->line);
        $this->assertSame(
            'Enum constant [App\\Status::Blue] may not be declared protected, use private instead',
            $violations[0]->message
        );
        $this->assertSame('Blue', $violations[0]->constantName);
        $this->assertSame(13, $violations[1]->line);
    }

    public function testFixChangesProtectedConstantToPrivate(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-enum-constant-');
        $this->assertIsString($file);

        file_put_contents($file, <<<'PHP'
<?php

enum Status
{
    protected const Grey = 'grey';

    case Draft;
}
PHP);

        try {
            $enumConstantMayNotBeProtectedRule = new EnumConstantMayNotBeProtectedRule('Source');

            $this->assertTrue($enumConstantMayNotBeProtectedRule->fix(new RuleViolation(
                message:   'Enum constant [Status::Grey] may not be declared protected, use private instead',
                file:      $file,
                line:      5,
                className: 'Status',
                constantName: 'Grey',
            )));

            $this->assertStringContainsString(
                "    private const Grey = 'grey';",
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    /**
     * @param list<ConstantNode> $constants
     */
    private function makeNode(
        array $constants,
        string $layer = 'Source',
        bool $isEnum = true,
    ): ClassNode {
        return new ClassNode(
            className: 'App\\Status',
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            constants: $constants,
            isEnum: $isEnum,
        );
    }
}
