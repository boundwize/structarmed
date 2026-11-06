<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumCaseValueMustNotHaveSurroundingWhitespaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mkdir;

#[CoversClass(EnumCaseValueMustNotHaveSurroundingWhitespaceRule::class)]
#[CoversClass(EnumCaseNode::class)]
final class EnumCaseValueMustNotHaveSurroundingWhitespaceRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAppliesOnlyToStringBackedEnumsInConfiguredLayer(): void
    {
        $enumCaseValueMustNotHaveSurroundingWhitespaceRule = new EnumCaseValueMustNotHaveSurroundingWhitespaceRule(
            'Source'
        );

        $this->assertTrue($enumCaseValueMustNotHaveSurroundingWhitespaceRule->appliesTo($this->makeNode([])));
        $this->assertFalse(
            $enumCaseValueMustNotHaveSurroundingWhitespaceRule->appliesTo($this->makeNode([], layer: 'Other'))
        );
        $this->assertFalse(
            $enumCaseValueMustNotHaveSurroundingWhitespaceRule->appliesTo($this->makeNode([], backingType: 'int'))
        );
        $this->assertFalse(
            $enumCaseValueMustNotHaveSurroundingWhitespaceRule->appliesTo($this->makeNode([], backingType: null))
        );
    }

    #[DataProvider('acceptedCaseProvider')]
    public function testAcceptsCase(EnumCaseNode $enumCaseNode): void
    {
        $enumCaseValueMustNotHaveSurroundingWhitespaceRule = new EnumCaseValueMustNotHaveSurroundingWhitespaceRule(
            'Source'
        );

        $classNode = $this->makeNode([$enumCaseNode]);

        $this->assertSame([], $enumCaseValueMustNotHaveSurroundingWhitespaceRule->evaluateAll($classNode));
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $enumCaseValueMustNotHaveSurroundingWhitespaceRule->evaluate($classNode)
        );
    }

    /** @return iterable<string, array{EnumCaseNode}> */
    public static function acceptedCaseProvider(): iterable
    {
        yield 'plain value'         => [new EnumCaseNode(name: 'Active', line: 5, value: 'active')];
        yield 'empty string'        => [new EnumCaseNode(name: 'Unknown', line: 5, value: '')];
        yield 'string zero'         => [new EnumCaseNode(name: 'Zero', line: 5, value: '0')];
        yield 'internal whitespace' => [new EnumCaseNode(name: 'Internal', line: 5, value: 'in progress')];
        yield 'integer value'       => [new EnumCaseNode(name: 'Low', line: 5, value: 0)];
        yield 'unresolved value'    => [new EnumCaseNode(name: 'Foreign', line: 5)];
    }

    #[DataProvider('rejectedValueProvider')]
    public function testRejectsValue(string $value): void
    {
        $enumCaseValueMustNotHaveSurroundingWhitespaceRule = new EnumCaseValueMustNotHaveSurroundingWhitespaceRule(
            'Source'
        );

        $classNode = $this->makeNode([new EnumCaseNode(name: 'Pending', line: 5, value: $value)]);

        $this->assertCount(1, $enumCaseValueMustNotHaveSurroundingWhitespaceRule->evaluateAll($classNode));
        $this->assertSame(
            'Enum case [App\Status::Pending] must not have surrounding whitespace in its backing value',
            $enumCaseValueMustNotHaveSurroundingWhitespaceRule->evaluate($classNode)?->message
        );
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedValueProvider(): iterable
    {
        yield 'leading space'     => [' active'];
        yield 'trailing space'    => ['active '];
        yield 'both sides'        => [' active '];
        yield 'trailing tab'      => ["active\t"];
        yield 'leading newline'   => ["\nactive"];
        yield 'single space'      => [' '];
        yield 'single tab'        => ["\t"];
        yield 'leading null byte' => ["\0active"];
    }

    public function testFlagsEveryCaseWithSurroundingWhitespaceWithMetadata(): void
    {
        $enumCaseValueMustNotHaveSurroundingWhitespaceRule = new EnumCaseValueMustNotHaveSurroundingWhitespaceRule(
            'Source'
        );

        $classNode = $this->makeNode([
            new EnumCaseNode(name: 'Active', line: 5, value: 'active'),
            new EnumCaseNode(name: 'Pending', line: 6, value: ' pending'),
            new EnumCaseNode(name: 'Unknown', line: 7, value: ''),
            new EnumCaseNode(name: 'Completed', line: 8, value: 'completed '),
            new EnumCaseNode(name: 'Missing', value: ' missing '),
        ]);

        $violations = $enumCaseValueMustNotHaveSurroundingWhitespaceRule->evaluateAll($classNode);

        $this->assertSame(
            [
                'Enum case [App\Status::Pending] must not have surrounding whitespace in its backing value',
                'Enum case [App\Status::Completed] must not have surrounding whitespace in its backing value',
                'Enum case [App\Status::Missing] must not have surrounding whitespace in its backing value',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations)
        );
        // A case without a recorded line is reported on the enum's line.
        $this->assertSame(
            [6, 8, 3],
            array_map(static fn (RuleViolation $ruleViolation): int => $ruleViolation->line, $violations)
        );
        $this->assertSame(
            ['Pending', 'Completed', 'Missing'],
            array_map(
                static fn (RuleViolation $ruleViolation): ?string => $ruleViolation->constantName,
                $violations
            )
        );
        $this->assertSame('/fake.php', $violations[0]->file);
        $this->assertSame('App\Status', $violations[0]->className);
        $this->assertSame('Source', $violations[0]->layer);
        $this->assertEquals($violations[0], $enumCaseValueMustNotHaveSurroundingWhitespaceRule->evaluate($classNode));
    }

    public function testAnalyseReportsSurroundingWhitespaceInBackingValuesOfStringBackedEnums(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-enum-case-value-surrounding-whitespace');
        mkdir($basePath . '/src');

        file_put_contents($basePath . '/src/Status.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            enum Status: string
            {
                const PADDED = 'padded ';

                case Active = 'active';
                case Pending = ' pending';
                case Unknown = '';
                case Space = ' ';
                case Zero = '0';
                case Internal = 'in progress';
                case Padded = self::PADDED;
                case Foreign = Other::PADDED;
            }

            enum Priority: int
            {
                case Low = 0;
            }

            enum Direction
            {
                case North;
            }

            PHP);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule(
                'source.enum_case_value',
                new EnumCaseValueMustNotHaveSurroundingWhitespaceRule('Source')
            );

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.enum_case_value');

        $this->assertSame(
            [
                [
                    'Enum case [App\Status::Pending] must not have surrounding whitespace in its backing value',
                    12,
                    'Pending',
                ],
                [
                    'Enum case [App\Status::Space] must not have surrounding whitespace in its backing value',
                    14,
                    'Space',
                ],
                [
                    'Enum case [App\Status::Padded] must not have surrounding whitespace in its backing value',
                    17,
                    'Padded',
                ],
            ],
            array_map(
                static fn (RuleViolation $ruleViolation): array => [
                    $ruleViolation->message,
                    $ruleViolation->line,
                    $ruleViolation->constantName,
                ],
                $violations
            )
        );
    }

    /**
     * @param list<EnumCaseNode> $enumCases
     */
    private function makeNode(
        array $enumCases,
        string $layer = 'Source',
        ?string $backingType = 'string',
    ): ClassNode {
        return new ClassNode(
            className: 'App\\Status',
            file: '/fake.php',
            line: 3,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            isEnum: true,
            enumCases: $enumCases,
            enumBackingType: $backingType,
        );
    }
}
