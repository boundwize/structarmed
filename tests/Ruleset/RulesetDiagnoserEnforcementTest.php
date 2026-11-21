<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Ruleset;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Ruleset\RulesetDiagnoser;
use Boundwize\StructArmed\Ruleset\RulesetDiagnostic;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_diff;
use function array_values;
use function count;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * Checks the diagnostics against the dependency enforcement they describe, on
 * a project where every layer depends on every other layer.
 */
#[CoversClass(RulesetDiagnoser::class)]
#[CoversClass(RulesetDiagnostic::class)]
#[UsesClass(Analyser::class)]
final class RulesetDiagnoserEnforcementTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private const LAYERS = ['A', 'B', 'C', 'D'];

    private const GENERATED_RULESETS = 300;

    public function testReportedEntriesAreTheOnesEnforcementDoesNotNeed(): void
    {
        $basePath = $this->makeProject();

        // Each layer has one class that depends on the three other layers.
        $this->assertCount(3, $this->violations($basePath, ['A' => []]));

        foreach ($this->rulesets() as $description => [$ruleset, $expectedRuleset]) {
            $violations = $this->violations($basePath, $ruleset);
            $reduced    = $ruleset;

            foreach ((new RulesetDiagnoser())->diagnose($this->architecture($ruleset)) as $diagnostic) {
                $reduced = $this->withoutEntry($reduced, $diagnostic->layer, $diagnostic->entry);
            }

            $this->assertSame($expectedRuleset, $reduced, $description);
            $this->assertSame(
                $violations,
                $this->violations($basePath, $reduced),
                sprintf('%s: removing every reported entry keeps the violations', $description)
            );

            // A plain entry that is not reported is needed, whatever else leads to its layer.
            foreach ($reduced as $layer => $entries) {
                foreach ($entries as $entry) {
                    if (str_starts_with($entry, '+')) {
                        continue;
                    }

                    $this->assertNotSame(
                        $violations,
                        $this->violations($basePath, $this->withoutEntry($reduced, $layer, $entry)),
                        sprintf('%s: removing %s from %s adds a violation', $description, $entry, $layer)
                    );
                }
            }
        }
    }

    /**
     * The hand-written rulesets above pin single cases; this one checks the same
     * promise on generated rulesets, where the layers and their entries come in
     * any order and + entries overlap and form cycles freely.
     */
    public function testReportedEntriesOfGeneratedRulesetsCanBeRemovedTogether(): void
    {
        $basePath   = $this->makeProject();
        $randomizer = new Randomizer(new Mt19937(20_261_121));
        $reported   = 0;

        for ($index = 0; $index < self::GENERATED_RULESETS; $index++) {
            $ruleset     = $this->generatedRuleset($randomizer);
            $diagnostics = (new RulesetDiagnoser())->diagnose($this->architecture($ruleset));

            if ($diagnostics === []) {
                continue;
            }

            $reduced = $ruleset;

            foreach ($diagnostics as $diagnostic) {
                $reduced = $this->withoutEntry($reduced, $diagnostic->layer, $diagnostic->entry);
            }

            $reported   += count($diagnostics);
            $description = (string) json_encode($ruleset);

            $this->assertSame(
                $this->violations($basePath, $ruleset),
                $this->violations($basePath, $reduced),
                $description
            );
            // With the reported entries gone, nothing else turns out to be removable.
            $this->assertSame([], (new RulesetDiagnoser())->diagnose($this->architecture($reduced)), $description);
        }

        $this->assertGreaterThan(self::GENERATED_RULESETS / 2, $reported);
    }

    /**
     * Every layer in a random position, listing about half of the other layers
     * in a random order, about half of those inherited with +.
     *
     * @return array<string, list<string>>
     */
    private function generatedRuleset(Randomizer $randomizer): array
    {
        $ruleset = [];

        foreach ($randomizer->shuffleArray(self::LAYERS) as $layer) {
            $ruleset[$layer] = [];

            foreach ($randomizer->shuffleArray(self::LAYERS) as $dependency) {
                // Of four draws, two leave the layer out, one lists it and one inherits it with +.
                $draw = $randomizer->getInt(0, 3);

                if ($dependency === $layer || $draw < 2) {
                    continue;
                }

                $ruleset[$layer][] = ($draw === 3 ? '+' : '') . $dependency;
            }
        }

        return $ruleset;
    }

    /**
     * Each ruleset, with what is left of it once the reported entries are removed.
     *
     * @return iterable<string, array{array<string, list<string>>, array<string, list<string>>}>
     */
    private function rulesets(): iterable
    {
        yield 'reachable through plain entries only' => [
            ['A' => ['B', 'C', 'D'], 'B' => ['C'], 'C' => ['D']],
            ['A' => ['B', 'C', 'D'], 'B' => ['C'], 'C' => ['D']],
        ];

        yield 'inherited through a + entry' => [
            ['A' => ['+B', 'C', 'D'], 'B' => ['C'], 'C' => ['D']],
            ['A' => ['+B', 'D'], 'B' => ['C'], 'C' => ['D']],
        ];

        yield 'inherited through several + entries' => [
            ['A' => ['+B', '+C', 'D'], 'B' => ['+C'], 'C' => ['D'], 'D' => ['A', 'B']],
            ['A' => ['+B'], 'B' => ['+C'], 'C' => ['D'], 'D' => ['A', 'B']],
        ];

        yield 'cycle of + entries with shared dependencies' => [
            ['A' => ['+B', 'C', 'D'], 'B' => ['+A', 'C', 'D'], 'C' => ['+D', 'A'], 'D' => ['+C', 'A', 'B']],
            ['A' => ['+B'], 'B' => ['+A', 'C', 'D'], 'C' => ['+D'], 'D' => ['+C', 'A', 'B']],
        ];

        yield '+ entries that inherit each other' => [
            ['A' => ['+B', '+C', 'D'], 'B' => ['+C', 'D', 'A'], 'C' => ['+B', 'A'], 'D' => ['+A', 'B', 'C']],
            ['A' => ['+C'], 'B' => ['+C', 'D'], 'C' => ['+B', 'A'], 'D' => ['+A']],
        ];
    }

    /**
     * @param array<string, list<string>> $ruleset
     * @return array<string, list<string>>
     */
    private function withoutEntry(array $ruleset, string $layer, string $entry): array
    {
        $ruleset[$layer] = array_values(array_diff($ruleset[$layer], [$entry]));

        return $ruleset;
    }

    /**
     * @param array<string, list<string>> $ruleset
     * @return list<string>
     */
    private function violations(string $basePath, array $ruleset): array
    {
        $messages = [];

        $ruleViolationCollection = (new Analyser($basePath))->analyse(
            $this->architecture($ruleset),
            ['src/'],
            null,
            AnalyserOptions::sequential()
        );

        foreach ($ruleViolationCollection as $ruleViolation) {
            $messages[] = $ruleViolation->message;
        }

        sort($messages);

        return $messages;
    }

    /**
     * @param array<string, list<string>> $ruleset
     */
    private function architecture(array $ruleset): Architecture
    {
        $architecture = Architecture::define()->ruleset($ruleset);

        foreach (self::LAYERS as $layer) {
            $architecture->layerPattern($layer, sprintf('/^App\\\\%s\\\\.*$/', $layer));
        }

        return $architecture;
    }

    private function makeProject(): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-ruleset');

        mkdir($basePath . '/src');

        foreach (self::LAYERS as $layer) {
            file_put_contents($basePath . '/src/' . $layer . '.php', sprintf(<<<'PHP'
                <?php

                namespace App\%s;

                final class Service
                {
                    public function __construct(
                        public \App\A\Service $a,
                        public \App\B\Service $b,
                        public \App\C\Service $c,
                        public \App\D\Service $d,
                    ) {
                    }
                }

                PHP, $layer));
        }

        return $basePath;
    }
}
