<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Ruleset\RulesetDiagnoser;
use Boundwize\StructArmed\Ruleset\RulesetDiagnostic;
use Boundwize\StructArmed\Util\Path;
use RuntimeException;

use function count;
use function explode;
use function implode;
use function is_dir;
use function max;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;

use const PHP_EOL;

/**
 * Reports issues in the configured ruleset itself. The config file is loaded
 * and its ruleset inspected; no source file is analysed and nothing is written.
 */
final readonly class DiagnoseRulesetCommand
{
    private const VALUE_OPTIONS = [
        '--config'   => 'config',
        '--basepath' => 'basepath',
        '-d'         => 'basepath',
    ];

    private const SEVERITY_COLORS = [
        RulesetDiagnostic::ERROR   => '91',
        RulesetDiagnostic::WARNING => '33',
    ];

    private const LEVEL_HEADING = 'Level';

    private const LAYER_HEADING = 'Layer';

    private const DEPENDENCY_HEADING = 'Dependency';

    private const ISSUE_HEADING = 'Issue';

    private const FIX_HEADING = 'Fix';

    private const ROW_FORMAT = '| %s | %s | %s | %s | %s |';

    private const BORDER_FORMAT = '+-%s-+-%s-+-%s-+-%s-+-%s-+';

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $options = [];
        $counter = count($arguments);

        for ($i = 0; $i < $counter; $i++) {
            $argument       = $arguments[$i];
            $optionAndValue = explode('=', $argument, 2);
            $option         = $optionAndValue[0];

            if (isset(self::VALUE_OPTIONS[$option])) {
                $options[self::VALUE_OPTIONS[$option]] = $optionAndValue[1] ?? $arguments[++$i] ?? '';
                continue;
            }

            echo sprintf("Unknown option: %s\n\n", $argument);
            echo Usage::render();

            return 1;
        }

        $workingDirectory = $basePath;

        if (isset($options['basepath'])) {
            $basePath = Path::normalise(Path::resolve($options['basepath'], $workingDirectory), canonicalise: true);

            if (! is_dir($basePath)) {
                echo sprintf("Error: base path [%s] not found.\n", $options['basepath']);

                return 1;
            }
        }

        try {
            $configFile   = $options['config'] ?? ConfigLoader::discover($workingDirectory, $basePath);
            $architecture = ConfigLoader::load($configFile);
        } catch (RuntimeException $runtimeException) {
            echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

            return 1;
        }

        $diagnostics = (new RulesetDiagnoser())->diagnose($architecture);
        $heading     = 'StructArmed Ruleset Diagnostics';
        $lines       = [
            $heading,
            str_repeat('=', strlen($heading)),
            // A relative config path is printed as the absolute path in effect.
            'Configuration: ' . Path::normalise($configFile, canonicalise: true),
            '',
        ];

        if ($diagnostics === []) {
            echo implode("\n", $lines) . "\nNo issues found.\n";

            return 0;
        }

        $counts     = [
            RulesetDiagnostic::ERROR   => 0,
            RulesetDiagnostic::WARNING => 0,
        ];
        $levelWidth = strlen(self::LEVEL_HEADING);
        $layerWidth = strlen(self::LAYER_HEADING);
        $dependencyWidth = strlen(self::DEPENDENCY_HEADING);
        $issueWidth = strlen(self::ISSUE_HEADING);
        $fixWidth   = strlen(self::FIX_HEADING);

        foreach ($diagnostics as $diagnostic) {
            $counts[$diagnostic->severity]++;

            $levelWidth = max($levelWidth, strlen($diagnostic->severity));
            $layerWidth = max($layerWidth, strlen($diagnostic->layer));
            $dependencyWidth = max($dependencyWidth, strlen($diagnostic->entry));
            $issueWidth = max($issueWidth, strlen($diagnostic->issue));
            $fixWidth   = max($fixWidth, strlen($diagnostic->fix));
        }

        $useColor = ColorSupport::detect();
        $border   = sprintf(
            self::BORDER_FORMAT,
            str_repeat('-', $levelWidth),
            str_repeat('-', $layerWidth),
            str_repeat('-', $dependencyWidth),
            str_repeat('-', $issueWidth),
            str_repeat('-', $fixWidth)
        );

        $lines[] = $border;
        $lines[] = sprintf(
            self::ROW_FORMAT,
            str_pad(self::LEVEL_HEADING, $levelWidth),
            str_pad(self::LAYER_HEADING, $layerWidth),
            str_pad(self::DEPENDENCY_HEADING, $dependencyWidth),
            str_pad(self::ISSUE_HEADING, $issueWidth),
            str_pad(self::FIX_HEADING, $fixWidth)
        );
        $lines[] = $border;

        foreach ($diagnostics as $diagnostic) {
            $lines[] = sprintf(
                self::ROW_FORMAT,
                ColorSupport::wrap(
                    str_pad($diagnostic->severity, $levelWidth),
                    self::SEVERITY_COLORS[$diagnostic->severity],
                    $useColor
                ),
                str_pad($diagnostic->layer, $layerWidth),
                str_pad($diagnostic->entry, $dependencyWidth),
                str_pad($diagnostic->issue, $issueWidth),
                str_pad($diagnostic->fix, $fixWidth)
            );
        }

        $lines[] = $border;
        $lines[] = sprintf(
            '%s, %s',
            $this->pluralise($counts[RulesetDiagnostic::WARNING], 'warning'),
            $this->pluralise($counts[RulesetDiagnostic::ERROR], 'error')
        );

        echo implode("\n", $lines) . "\n";

        // Warnings are advisory: only an invalid ruleset fails the command.
        return $counts[RulesetDiagnostic::ERROR] > 0 ? 1 : 0;
    }

    private function pluralise(int $count, string $noun): string
    {
        return sprintf('%d %s%s', $count, $noun, $count === 1 ? '' : 's');
    }
}
