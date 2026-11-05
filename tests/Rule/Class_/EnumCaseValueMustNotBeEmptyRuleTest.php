<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumCaseValueMustNotBeEmptyRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mkdir;

#[CoversClass(EnumCaseValueMustNotBeEmptyRule::class)]
#[CoversClass(EnumCaseNode::class)]
final class EnumCaseValueMustNotBeEmptyRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAppliesOnlyToStringBackedEnumsInConfiguredLayer(): void
    {
        $enumCaseValueMustNotBeEmptyRule = new EnumCaseValueMustNotBeEmptyRule('Source');

        $this->assertTrue($enumCaseValueMustNotBeEmptyRule->appliesTo($this->makeNode([])));
        $this->assertFalse($enumCaseValueMustNotBeEmptyRule->appliesTo($this->makeNode([], layer: 'Other')));
        $this->assertFalse($enumCaseValueMustNotBeEmptyRule->appliesTo($this->makeNode([], backingType: 'int')));
        $this->assertFalse($enumCaseValueMustNotBeEmptyRule->appliesTo($this->makeNode([], backingType: null)));
    }

    #[DataProvider('acceptedCaseProvider')]
    public function testAcceptsCase(EnumCaseNode $enumCaseNode): void
    {
        $enumCaseValueMustNotBeEmptyRule = new EnumCaseValueMustNotBeEmptyRule('Source');
        $classNode                       = $this->makeNode([$enumCaseNode]);

        $this->assertSame([], $enumCaseValueMustNotBeEmptyRule->evaluateAll($classNode));
        $this->assertNotInstanceOf(RuleViolation::class, $enumCaseValueMustNotBeEmptyRule->evaluate($classNode));
    }

    /** @return iterable<string, array{EnumCaseNode}> */
    public static function acceptedCaseProvider(): iterable
    {
        yield 'non-empty string' => [new EnumCaseNode(name: 'Active', line: 5, value: 'active')];
        yield 'whitespace only'  => [new EnumCaseNode(name: 'Space', line: 5, value: ' ')];
        yield 'string zero'      => [new EnumCaseNode(name: 'Unknown', line: 5, value: '0')];
        yield 'integer zero'     => [new EnumCaseNode(name: 'Low', line: 5, value: 0)];
        yield 'unresolved value' => [new EnumCaseNode(name: 'Foreign', line: 5)];
    }

    public function testFlagsEveryEmptyCaseWithMetadata(): void
    {
        $enumCaseValueMustNotBeEmptyRule = new EnumCaseValueMustNotBeEmptyRule('Source');
        $classNode                       = $this->makeNode([
            new EnumCaseNode(name: 'Active', line: 5, value: 'active'),
            new EnumCaseNode(name: 'Pending', line: 6, value: ''),
            new EnumCaseNode(name: 'Unknown', line: 7, value: '0'),
            new EnumCaseNode(name: 'Missing', value: ''),
        ]);

        $violations = $enumCaseValueMustNotBeEmptyRule->evaluateAll($classNode);

        $this->assertSame(
            [
                'Enum case [App\Status::Pending] must not have an empty backing value',
                'Enum case [App\Status::Missing] must not have an empty backing value',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations)
        );
        // A case without a recorded line is reported on the enum's line.
        $this->assertSame(
            [6, 3],
            array_map(static fn (RuleViolation $ruleViolation): int => $ruleViolation->line, $violations)
        );
        $this->assertSame(
            ['Pending', 'Missing'],
            array_map(
                static fn (RuleViolation $ruleViolation): ?string => $ruleViolation->constantName,
                $violations
            )
        );
        $this->assertSame('/fake.php', $violations[0]->file);
        $this->assertSame('App\Status', $violations[0]->className);
        $this->assertSame('Source', $violations[0]->layer);
        $this->assertEquals($violations[0], $enumCaseValueMustNotBeEmptyRule->evaluate($classNode));
    }

    public function testAnalyseReportsEmptyBackingValuesOfStringBackedEnums(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-enum-case-value-not-empty');
        mkdir($basePath . '/src');

        file_put_contents($basePath . '/src/Status.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            enum Status: string
            {
                const NONE = '';

                case Active = 'active';
                case Pending = '';
                case Unknown = '0';
                case Space = ' ';
                case None = self::NONE;
                case Foreign = Other::EMPTY;
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
            ->rule('source.enum_case_value', new EnumCaseValueMustNotBeEmptyRule('Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.enum_case_value');

        $this->assertSame(
            [
                ['Enum case [App\Status::Pending] must not have an empty backing value', 12, 'Pending'],
                ['Enum case [App\Status::None] must not have an empty backing value', 15, 'None'],
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
