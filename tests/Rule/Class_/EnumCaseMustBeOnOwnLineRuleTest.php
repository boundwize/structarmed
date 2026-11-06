<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\EnumCase\MoveEnumCaseToOwnLineVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumCaseMustBeOnOwnLineRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function file_get_contents;
use function file_put_contents;
use function mkdir;

#[CoversClass(EnumCaseMustBeOnOwnLineRule::class)]
#[CoversClass(EnumCaseNode::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(MoveEnumCaseToOwnLineVisitor::class)]
final class EnumCaseMustBeOnOwnLineRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAppliesOnlyToEnumsInConfiguredLayer(): void
    {
        $enumCaseMustBeOnOwnLineRule = new EnumCaseMustBeOnOwnLineRule('Source');

        $this->assertTrue($enumCaseMustBeOnOwnLineRule->appliesTo($this->makeNode([])));
        $this->assertFalse($enumCaseMustBeOnOwnLineRule->appliesTo($this->makeNode([], layer: 'Other')));
        $this->assertFalse($enumCaseMustBeOnOwnLineRule->appliesTo($this->makeNode([], isEnum: false)));
    }

    public function testPassesCasesOnTheirOwnLine(): void
    {
        $enumCaseMustBeOnOwnLineRule = new EnumCaseMustBeOnOwnLineRule('Source');
        $classNode                   = $this->makeNode([
            new EnumCaseNode(name: 'Active', line: 5, value: 'active'),
            new EnumCaseNode(name: 'Inactive', line: 6),
        ]);

        $this->assertSame([], $enumCaseMustBeOnOwnLineRule->evaluateAll($classNode));
        $this->assertNotInstanceOf(RuleViolation::class, $enumCaseMustBeOnOwnLineRule->evaluate($classNode));
    }

    public function testFlagsEveryCaseSharingALine(): void
    {
        $enumCaseMustBeOnOwnLineRule = new EnumCaseMustBeOnOwnLineRule('Source');
        $classNode                   = $this->makeNode([
            new EnumCaseNode(name: 'Active', line: 5, sharesLine: true),
            new EnumCaseNode(name: 'Inactive', line: 5, sharesLine: true),
            new EnumCaseNode(name: 'Pending', line: 6),
            new EnumCaseNode(name: 'Unknown', sharesLine: true),
        ]);

        $violations = $enumCaseMustBeOnOwnLineRule->evaluateAll($classNode);

        $this->assertSame(
            [
                'Enum case [App\Status::Active] must be declared on its own line',
                'Enum case [App\Status::Inactive] must be declared on its own line',
                'Enum case [App\Status::Unknown] must be declared on its own line',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations)
        );
        // A case without a recorded line is reported on the enum's line.
        $this->assertSame(
            [5, 5, 3],
            array_map(static fn (RuleViolation $ruleViolation): int => $ruleViolation->line, $violations)
        );
        $this->assertSame('Active', $violations[0]->constantName);
        $this->assertSame('App\Status', $violations[0]->className);
        $this->assertSame('/src/Status.php', $violations[0]->file);
        $this->assertSame('Source', $violations[0]->layer);
        $this->assertEquals($violations[0], $enumCaseMustBeOnOwnLineRule->evaluate($classNode));
    }

    public function testAnalyseThenFixSeparatesCasesWithAndWithoutCache(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-enum-case-own-line');
        mkdir($basePath . '/src');

        $file = $basePath . '/src/Status.php';

        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            enum Status: string
            {
                // Available status values
                case Active = 'active'; case Inactive = 'inactive'; /* Pending status */ case Pending = 'pending';

                case Done = 'done'
                    . '!'; const Default = self::Active;

                public function label(): string
                {
                    return $this->value;
                }
            }

            enum Pure { case One; case Two; }

            enum Valid
            {
                case One; // trailing comment
                case Two;
            }

            PHP);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.enum_cases', new EnumCaseMustBeOnOwnLineRule('Source'));

        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'cache');
        $runs                = [
            'uncached'   => [null, AnalyserOptions::sequential()],
            'parallel'   => [null, AnalyserOptions::parallel(2)],
            'cache cold' => [$analysisResultCache, AnalyserOptions::sequential()],
            'cache warm' => [$analysisResultCache, AnalyserOptions::sequential()],
        ];

        foreach ($runs as $run => [$cache, $analyserOptions]) {
            $violations = (new Analyser($basePath, $cache, 'config'))
                ->analyse($architecture, [], null, $analyserOptions)
                ->forRule('source.enum_cases');

            $this->assertSame(
                [
                    'Active@10',
                    'Inactive@10',
                    'Pending@10',
                    'Done@12',
                    'One@21',
                    'Two@21',
                ],
                array_map(
                    static fn (RuleViolation $ruleViolation): string => $ruleViolation->constantName
                        . '@' . $ruleViolation->line,
                    $violations
                ),
                $run
            );
        }

        $this->assertTrue($violations[0]->fixable);

        $rule = $architecture->getRules()['source.enum_cases'];
        $this->assertInstanceOf(EnumCaseMustBeOnOwnLineRule::class, $rule);

        // The CLI fixes one file's violations in a single parse-and-write cycle.
        $this->assertTrue($rule->fix($violations[0], ...array_slice($violations, 1)));

        $fixed = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            enum Status: string
            {
                // Available status values
                case Active = 'active';
                // Available status values
                case Inactive = 'inactive';
                /* Pending status */
                case Pending = 'pending';

                case Done = 'done'
                    . '!';
                const Default = self::Active;

                public function label(): string
                {
                    return $this->value;
                }
            }

            enum Pure {
                case One;
                case Two;
            }

            enum Valid
            {
                case One; // trailing comment
                case Two;
            }

            PHP;

        $this->assertSame($fixed, file_get_contents($file));

        // Re-analysis of the fixed file, fresh and through the cache, is
        // clean, and fixing again changes nothing. A new run hashes the
        // files anew, as the CLI does.
        foreach ([null, new AnalysisResultCache($basePath, new FileHashProvider(), 'cache')] as $cache) {
            $this->assertSame(
                [],
                (new Analyser($basePath, $cache, 'config'))
                    ->analyse($architecture, [], null, AnalyserOptions::sequential())
                    ->forRule('source.enum_cases')
            );
        }

        $this->assertFalse($rule->fix($violations[0], ...array_slice($violations, 1)));
        $this->assertSame($fixed, file_get_contents($file));
    }

    /** @param list<EnumCaseNode> $enumCases */
    private function makeNode(array $enumCases, string $layer = 'Source', bool $isEnum = true): ClassNode
    {
        return new ClassNode(
            className:   'App\\Status',
            file:        '/src/Status.php',
            line:        3,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: false,
            isReadonly:  false,
            isEnum:      $isEnum,
            enumCases:   $enumCases,
        );
    }
}
