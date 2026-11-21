<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Ruleset;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Ruleset\RulesetDiagnoser;
use Boundwize\StructArmed\Ruleset\RulesetDiagnostic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sprintf;

#[CoversClass(RulesetDiagnoser::class)]
#[CoversClass(RulesetDiagnostic::class)]
final class RulesetDiagnoserTest extends TestCase
{
    /**
     * Each expected diagnostic reads `LEVEL Layer[Entry]: Issue => Fix`.
     *
     * @return iterable<string, array{array<string, list<string>>, list<string>}>
     */
    public static function inheritedDependencyProvider(): iterable
    {
        yield 'no ruleset' => [[], []];

        yield 'layers without dependencies' => [['A' => [], 'B' => []], []];

        yield 'no alternative path' => [['A' => ['B', 'C']], []];

        yield 'dependency inherited through a + entry' => [
            ['A' => ['+B', 'C'], 'B' => ['C']],
            [
                "WARNING A[C]: Already inherited through '+B'"
                . " => Remove 'C' from the dependencies of layer 'A'",
            ],
        ];

        yield 'dependency inherited through several + entries' => [
            ['A' => ['+B', 'D'], 'B' => ['+C'], 'C' => ['D']],
            [
                "WARNING A[D]: Already inherited through '+B'"
                . " => Remove 'D' from the dependencies of layer 'A'",
            ],
        ];

        // A plain entry allows its layer alone, so A still needs its own entry: no issue.
        yield 'dependency reachable through a plain entry' => [['A' => ['B', 'C'], 'B' => ['C']], []];

        yield 'dependency reachable through several plain entries' => [
            ['A' => ['B', 'D'], 'B' => ['C'], 'C' => ['D']],
            [],
        ];

        yield 'inheritance stops at the first plain entry' => [
            ['A' => ['+B', 'D'], 'B' => ['C'], 'C' => ['D']],
            [],
        ];

        yield 'inherited path wins over a plain path declared first' => [
            ['A' => ['D', '+B', 'C'], 'D' => ['C'], 'B' => ['C']],
            [
                "WARNING A[C]: Already inherited through '+B'"
                . " => Remove 'C' from the dependencies of layer 'A'",
            ],
        ];

        yield 'shortest path names the entry' => [
            ['A' => ['+B', '+E', 'D'], 'B' => ['+C'], 'C' => ['D'], 'E' => ['D']],
            [
                "WARNING A[D]: Already inherited through '+E'"
                . " => Remove 'D' from the dependencies of layer 'A'",
            ],
        ];

        yield '+ entry inherited through another + entry' => [
            ['A' => ['+B', '+C'], 'B' => ['+C'], 'C' => ['D']],
            [
                "WARNING A[+C]: Already inherited through '+B'"
                . " => Remove '+C' from the dependencies of layer 'A'",
            ],
        ];

        // B allows C alone, without the layers that C is allowed to depend on.
        yield '+ entry is not replaced by a plain entry to the same layer' => [
            ['A' => ['+B', '+C'], 'B' => ['C'], 'C' => ['D']],
            [],
        ];

        yield 'cycle without alternative path' => [['A' => ['B'], 'B' => ['A']], []];

        yield 'cycle of + entries without alternative path' => [['A' => ['+B'], 'B' => ['+A']], []];

        yield 'cycle of plain entries with a shared dependency' => [
            ['A' => ['B', 'C'], 'B' => ['A', 'C']],
            [],
        ];

        // Both entries inherit C from each other: removing both would forbid C, so one is reported.
        yield 'cycle of + entries with a shared dependency' => [
            ['A' => ['+B', 'C'], 'B' => ['+A', 'C']],
            [
                "WARNING A[C]: Already inherited through '+B'"
                . " => Remove 'C' from the dependencies of layer 'A'",
            ],
        ];

        yield '+ entries that inherit each other' => [
            ['A' => ['+B', '+C'], 'B' => ['+C'], 'C' => ['+B']],
            [
                "WARNING A[+B]: Already inherited through '+C'"
                . " => Remove '+B' from the dependencies of layer 'A'",
            ],
        ];

        yield 'self-dependency' => [
            ['A' => ['A', 'B'], 'B' => ['+B']],
            [
                "WARNING A[A]: Same layer, always allowed"
                . " => Remove 'A' from the dependencies of layer 'A'",
                "WARNING B[+B]: Same layer, always allowed"
                . " => Remove '+B' from the dependencies of layer 'B'",
            ],
        ];

        yield 'duplicate dependency' => [
            ['A' => ['B', 'C', 'B', 'B']],
            [
                "WARNING A[B]: Listed more than once"
                . " => Keep a single 'B' in the dependencies of layer 'A'",
            ],
        ];

        // The + entry of a repeated dependency still inherits its allowed layers.
        yield 'duplicate dependency declared with and without +' => [
            ['A' => ['B', '+B', 'C'], 'B' => ['C']],
            [
                "WARNING A[+B]: Listed more than once"
                . " => Keep a single '+B' in the dependencies of layer 'A'",
                "WARNING A[C]: Already inherited through '+B'"
                . " => Remove 'C' from the dependencies of layer 'A'",
            ],
        ];

        yield 'repeated entry that has to go gets that fix alone' => [
            ['A' => ['A', 'A', '+B', 'C', 'C', 'Missing', 'Missing'], 'B' => ['C']],
            [
                "WARNING A[A]: Same layer, always allowed"
                . " => Remove 'A' from the dependencies of layer 'A'",
                "WARNING A[C]: Already inherited through '+B'"
                . " => Remove 'C' from the dependencies of layer 'A'",
                "ERROR A[Missing]: Undefined layer"
                . " => Define layer 'Missing', or remove 'Missing' from the dependencies of layer 'A'",
            ],
        ];

        yield 'issues follow the order of the ruleset' => [
            ['A' => ['B', 'C', 'Missing'], 'B' => ['C', 'B'], 'C' => ['+D', 'E'], 'D' => ['E']],
            [
                "ERROR A[Missing]: Undefined layer"
                . " => Define layer 'Missing', or remove 'Missing' from the dependencies of layer 'A'",
                "WARNING B[B]: Same layer, always allowed"
                . " => Remove 'B' from the dependencies of layer 'B'",
                "WARNING C[E]: Already inherited through '+D'"
                . " => Remove 'E' from the dependencies of layer 'C'",
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $ruleset
     * @param list<string>                $expected
     */
    #[DataProvider('inheritedDependencyProvider')]
    public function testDiagnosesInheritedDependencies(array $ruleset, array $expected): void
    {
        $this->assertSame($expected, $this->diagnose($ruleset, ['A', 'B', 'C', 'D', 'E']));
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, list<string>}>
     */
    public static function undefinedLayerProvider(): iterable
    {
        yield 'layers defined by layer() and layerPattern()' => [
            ['Domain' => ['Pattern'], 'Pattern' => ['Domain']],
            [],
        ];

        yield 'Source layer filled from composer.json' => [
            ['Source' => ['Domain'], 'Domain' => ['Source', 'Source[tests]']],
            [],
        ];

        yield 'ruleset key that is not a layer' => [
            ['Undefined' => ['Pattern']],
            [
                "ERROR Undefined[]: Undefined layer"
                . " => Define layer 'Undefined', or remove 'Undefined' from the ruleset",
            ],
        ];

        yield 'dependency that is not a layer' => [
            ['Domain' => ['Missing', '+Unknown']],
            [
                "ERROR Domain[Missing]: Undefined layer"
                . " => Define layer 'Missing', or remove 'Missing' from the dependencies of layer 'Domain'",
                "ERROR Domain[+Unknown]: Undefined layer"
                . " => Define layer 'Unknown', or remove '+Unknown' from the dependencies of layer 'Domain'",
            ],
        ];

        // A ruleset key that is no layer, inherited with +, names a group of allowed layers.
        yield 'ruleset key inherited with + groups allowed layers' => [
            ['Shared' => ['Pattern'], 'Domain' => ['+Shared', 'Pattern']],
            [
                "WARNING Domain[Pattern]: Already inherited through '+Shared'"
                . " => Remove 'Pattern' from the dependencies of layer 'Domain'",
            ],
        ];

        yield 'ruleset key that is no layer allows nothing without +' => [
            ['Shared' => ['Pattern'], 'Domain' => ['Shared']],
            [
                "ERROR Shared[]: Undefined layer"
                . " => Define layer 'Shared', or remove 'Shared' from the ruleset",
                "ERROR Domain[Shared]: Undefined layer"
                . " => Replace 'Shared' with '+Shared' in the dependencies of layer 'Domain'",
            ],
        ];

        yield 'undefined dependency is not followed' => [
            ['Domain' => ['Shared', 'Pattern'], 'Shared' => ['Pattern']],
            [
                "ERROR Domain[Shared]: Undefined layer"
                . " => Replace 'Shared' with '+Shared' in the dependencies of layer 'Domain'",
                "ERROR Shared[]: Undefined layer"
                . " => Define layer 'Shared', or remove 'Shared' from the ruleset",
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $ruleset
     * @param list<string>                $expected
     */
    #[DataProvider('undefinedLayerProvider')]
    public function testDiagnosesUndefinedLayers(array $ruleset, array $expected): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layerPattern('Pattern', '/^App\\\\Pattern\\\\.*$/')
            ->ruleset($ruleset);

        $this->assertSame($expected, $this->summarise((new RulesetDiagnoser())->diagnose($architecture)));
    }

    public function testDiagnosticPointsAtTheRulesetEntry(): void
    {
        $architecture = Architecture::define()
            ->layer('A', 'src/A/')
            ->layer('B', 'src/B/')
            ->layer('C', 'src/C/')
            ->ruleset([
                'A'      => ['+B', 'C'],
                'B'      => ['C'],
                'Absent' => [],
            ]);

        $this->assertEquals(
            [
                new RulesetDiagnostic(
                    RulesetDiagnostic::WARNING,
                    'A',
                    'C',
                    "Already inherited through '+B'",
                    "Remove 'C' from the dependencies of layer 'A'",
                ),
                new RulesetDiagnostic(
                    RulesetDiagnostic::ERROR,
                    'Absent',
                    '',
                    'Undefined layer',
                    "Define layer 'Absent', or remove 'Absent' from the ruleset",
                ),
            ],
            (new RulesetDiagnoser())->diagnose($architecture)
        );
    }

    public function testDiagnosesTheSameRulesetTheSameWay(): void
    {
        $ruleset = [
            'A' => ['+B', 'C', 'D', 'E'],
            'B' => ['+C', 'A', 'E'],
            'C' => ['+A', 'D', 'B'],
            'D' => ['E', 'A'],
            'E' => ['+D', 'C'],
        ];
        $layers  = ['A', 'B', 'C', 'D', 'E'];

        $this->assertSame($this->diagnose($ruleset, $layers), $this->diagnose($ruleset, $layers));
        $this->assertNotSame([], $this->diagnose($ruleset, $layers));
    }

    /**
     * @param array<string, list<string>> $ruleset
     * @param list<string>                $layers
     * @return list<string>
     */
    private function diagnose(array $ruleset, array $layers): array
    {
        $architecture = Architecture::define()->ruleset($ruleset);

        foreach ($layers as $layer) {
            $architecture->layerPattern($layer, sprintf('/^App\\\\%s\\\\.*$/', $layer));
        }

        return $this->summarise((new RulesetDiagnoser())->diagnose($architecture));
    }

    /**
     * @param list<RulesetDiagnostic> $diagnostics
     * @return list<string>
     */
    private function summarise(array $diagnostics): array
    {
        return array_map(
            static fn(RulesetDiagnostic $rulesetDiagnostic): string => sprintf(
                '%s %s[%s]: %s => %s',
                $rulesetDiagnostic->severity,
                $rulesetDiagnostic->layer,
                $rulesetDiagnostic->entry,
                $rulesetDiagnostic->issue,
                $rulesetDiagnostic->fix
            ),
            $diagnostics
        );
    }
}
