<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;
use RuntimeException;

use function count;
use function in_array;
use function is_dir;
use function ltrim;
use function microtime;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const PHP_EOL;

final readonly class AnalyseCommand
{
    public function __construct(private ?ProgressHandlerInterface $progressHandler = null)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $options   = [];
        $scanPaths = [];
        $counter   = count($arguments);

        for ($i = 0; $i < $counter; $i++) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--report=')) {
                $options['report'] = substr($argument, strlen('--report='));
                continue;
            }

            if ($argument === '--report') {
                $options['report'] = $arguments[++$i] ?? '';
                continue;
            }

            if (str_starts_with($argument, '--config=')) {
                $options['config'] = substr($argument, strlen('--config='));
                continue;
            }

            if ($argument === '--config') {
                $options['config'] = $arguments[++$i] ?? '';
                continue;
            }

            if ($argument === '--no-progress') {
                $options['progress'] = false;
                continue;
            }

            if (str_starts_with($argument, '--')) {
                echo sprintf("Unknown option: %s\n\n", $argument);
                echo Usage::render();

                return 1;
            }

            $scanPaths[] = $argument;
        }

        $reportType = $options['report'] ?? 'console';

        if (! in_array($reportType, ['console', 'json'], true)) {
            echo sprintf("Invalid report type: %s\n\n", $reportType);
            echo Usage::render();

            return 1;
        }

        foreach ($scanPaths as $scanPath) {
            $fullScanPath = rtrim($basePath, '/') . '/' . ltrim($scanPath, '/');

            if (! is_dir($fullScanPath)) {
                echo sprintf("Error: directory [%s] not found.\n", $scanPath);

                return 1;
            }
        }

        try {
            $configFile   = $options['config'] ?? ConfigLoader::discover($basePath);
            $architecture = ConfigLoader::load($configFile);
        } catch (RuntimeException $runtimeException) {
            echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

            return 1;
        }

        $start           = microtime(true);
        $analyser        = new Analyser($basePath);
        $progressEnabled = $options['progress'] ?? true;
        $progress        = $reportType === 'console' && $progressEnabled
            ? $this->progressHandler ?? new ConsoleProgressBar()
            : null;

        $ruleViolationCollection = $analyser->analyse($architecture, $scanPaths, $progress);
        $elapsed                 = microtime(true) - $start;

        $report = match ($reportType) {
            'json' => (new JsonReport())->render($ruleViolationCollection, $elapsed),
            default => (new ConsoleReport())->render($ruleViolationCollection, $elapsed),
        };

        echo $report;

        return $ruleViolationCollection->hasViolations() ? 1 : 0;
    }
}
