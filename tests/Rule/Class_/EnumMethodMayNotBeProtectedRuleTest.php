<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod\ChangeProtectedMethodToPrivateVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumMethodMayNotBeProtectedRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(EnumMethodMayNotBeProtectedRule::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(ChangeProtectedMethodToPrivateVisitor::class)]
final class EnumMethodMayNotBeProtectedRuleTest extends TestCase
{
    public function testAppliesOnlyToEnumsInConfiguredLayer(): void
    {
        $enumMethodMayNotBeProtectedRule = new EnumMethodMayNotBeProtectedRule('Source');

        $this->assertTrue($enumMethodMayNotBeProtectedRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($enumMethodMayNotBeProtectedRule->appliesTo($this->makeNode([], 'Other')));
        $this->assertFalse($enumMethodMayNotBeProtectedRule->appliesTo(
            $this->makeNode([], 'Source', isEnum: false)
        ));
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $enumMethodMayNotBeProtectedRule = new EnumMethodMayNotBeProtectedRule('Source');

        $violation = $enumMethodMayNotBeProtectedRule->evaluate(
            $this->makeNode([
                $this->makeMethod('label', 'protected'),
                $this->makeMethod('color', 'protected'),
            ])
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(1, $violation->line);
    }

    #[DataProvider('allowedVisibilityProvider')]
    public function testPassesNonProtectedMethods(string $visibility): void
    {
        $enumMethodMayNotBeProtectedRule = new EnumMethodMayNotBeProtectedRule('Source');

        $this->assertSame(
            [],
            $enumMethodMayNotBeProtectedRule->evaluateAll(
                $this->makeNode([$this->makeMethod('label', $visibility)])
            )
        );
    }

    /** @return iterable<string, array{string}> */
    public static function allowedVisibilityProvider(): iterable
    {
        yield 'public'  => ['public'];
        yield 'private' => ['private'];
    }

    public function testViolatesProtectedMethods(): void
    {
        $enumMethodMayNotBeProtectedRule = new EnumMethodMayNotBeProtectedRule('Source');

        $violations = $enumMethodMayNotBeProtectedRule->evaluateAll(
            $this->makeNode([
                $this->makeMethod('label', 'public', line: 5),
                $this->makeMethod('color', 'protected', line: 9),
                $this->makeMethod('icon', 'protected', line: 13),
            ])
        );

        $this->assertCount(2, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertSame(9, $violations[0]->line);
        $this->assertSame(
            'Enum method [App\\Status::color] may not be declared protected, use private instead',
            $violations[0]->message
        );
        $this->assertSame('color', $violations[0]->methodName);
        $this->assertSame(13, $violations[1]->line);
    }

    public function testFixChangesProtectedMethodToPrivate(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'structarmed-enum-method-');
        $this->assertIsString($file);

        file_put_contents($file, <<<'PHP'
<?php

enum Status
{
    case Draft;

    protected static function color(): string
    {
        return 'grey';
    }
}
PHP);

        try {
            $enumMethodMayNotBeProtectedRule = new EnumMethodMayNotBeProtectedRule('Source');

            $this->assertTrue($enumMethodMayNotBeProtectedRule->fix(new RuleViolation(
                message:   'Enum method [Status::color] may not be declared protected, use private instead',
                file:      $file,
                line:      7,
                className: 'Status',
                methodName: 'color',
            )));

            $this->assertStringContainsString(
                '    private static function color(): string',
                (string) file_get_contents($file)
            );
        } finally {
            unlink($file);
        }
    }

    private function makeMethod(string $name, string $visibility, int $line = 0): MethodNode
    {
        return new MethodNode(
            name: $name,
            visibility: $visibility,
            hasReturnType: true,
            isStatic: false,
            paramCount: 0,
            cyclomaticComplexity: 1,
            lineCount: 3,
            hasExplicitVisibility: true,
            line: $line,
        );
    }

    /**
     * @param list<MethodNode> $methods
     */
    private function makeNode(
        array $methods,
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
            methods: $methods,
            isEnum: $isEnum,
        );
    }
}
