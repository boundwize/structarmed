<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
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

            if ($argument === '--clear-cache') {
                $options['clear-cache'] = true;
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

        $start                        = microtime(true);
        $analyser                     = new Analyser($basePath);
        $analysisResultCache          = new AnalysisResultCache($basePath, $architecture->getCacheDirectory());
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configHash                   = $analysisCacheMetadataFactory->fileHash($configFile);

        $shouldClearCache = isset($options['clear-cache'])
            || $analysisResultCache->hasDifferentConfig($configHash);

        if ($shouldClearCache) {
            $analysisResultCache->clear();
        }

        $files           = $analyser->filesForAnalysis($architecture, $scanPaths);
        $metadata        = $analysisCacheMetadataFactory->metadata($basePath, $configFile, $scanPaths, $files);
        $cacheKey        = $analysisCacheMetadataFactory->key($metadata);
        $progressEnabled = $options['progress'] ?? true;
        $progress        = $reportType === 'console' && $progressEnabled
            ? $this->progressHandler ?? new ConsoleProgressBar()
            : null;

        $ruleViolationCollection = $analysisResultCache->load($cacheKey, $metadata);

        if (! $ruleViolationCollection instanceof RuleViolationCollection) {
            $ruleViolationCollection = $analyser->analyse($architecture, $scanPaths, $progress);
            $analysisResultCache->store($cacheKey, $metadata, $ruleViolationCollection);
        }

        $elapsed = microtime(true) - $start;

        $report = match ($reportType) {
            'json' => (new JsonReport())->render($ruleViolationCollection, $elapsed),
            default => (new ConsoleReport())->render($ruleViolationCollection, $elapsed),
        };

        echo $report;

        return $ruleViolationCollection->hasViolations() ? 1 : 0;
    }
}
