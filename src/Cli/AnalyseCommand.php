<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Baseline\Baseline;
use Boundwize\StructArmed\Baseline\BaselineFilter;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
use RuntimeException;

use function count;
use function in_array;
use function is_dir;
use function is_file;
use function microtime;
use function sprintf;
use function str_ends_with;
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

            if ($argument === '--disable-parallel') {
                $options['disable-parallel'] = true;
                continue;
            }

            if ($argument === '--fix') {
                $options['fix'] = true;
                continue;
            }

            if (str_starts_with($argument, '--generate-baseline=')) {
                $options['generate-baseline'] = substr($argument, strlen('--generate-baseline='));
                continue;
            }

            if ($argument === '--generate-baseline') {
                $options['generate-baseline'] = $arguments[++$i] ?? '';
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
            $fullScanPath = Path::resolve($scanPath, $basePath);

            if (is_dir($fullScanPath)) {
                continue;
            }

            if (is_file($fullScanPath) && str_ends_with($fullScanPath, '.php')) {
                continue;
            }

            echo sprintf("Error: path [%s] not found.\n", $scanPath);

            return 1;
        }

        try {
            $configFile   = $options['config'] ?? ConfigLoader::discover($basePath);
            $architecture = ConfigLoader::load($configFile);
        } catch (RuntimeException $runtimeException) {
            echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

            return 1;
        }

        $start                        = microtime(true);
        $analysisResultCache          = new AnalysisResultCache($basePath, $architecture->getCacheDirectory());
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configHash                   = $analysisCacheMetadataFactory->fileHash($configFile);
        $analyser                     = new Analyser($basePath, $analysisResultCache, $configHash);
        $composerGeneratedVersionHash = $analysisCacheMetadataFactory->composerGeneratedVersionHash();

        $shouldClearCache = isset($options['clear-cache'])
            || $analysisResultCache->hasDifferentConfig($configHash)
            || $analysisResultCache->hasDifferentComposerGeneratedVersion($composerGeneratedVersionHash);

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
        $analyserOptions = isset($options['disable-parallel']) ? AnalyserOptions::sequential() : null;

        $ruleViolationCollection = $analysisResultCache->load($cacheKey, $metadata);

        if (! $ruleViolationCollection instanceof RuleViolationCollection) {
            $ruleViolationCollection = $analyser->analyse(
                $architecture,
                $scanPaths,
                $progress,
                $analyserOptions,
                $files
            );
            $analysisResultCache->store($cacheKey, $metadata, $ruleViolationCollection);
        }

        $elapsed                           = microtime(true) - $start;
        $baseline                          = new Baseline();
        $unfilteredRuleViolationCollection = $ruleViolationCollection;
        $shouldGenerateBaseline            = isset($options['generate-baseline']);
        $fixedCount                        = 0;

        if (isset($options['fix'])) {
            try {
                $ruleViolationCollection = $this->resolveRuleViolationCollection(
                    $unfilteredRuleViolationCollection,
                    $architecture,
                    $basePath,
                    $shouldGenerateBaseline
                );
            } catch (RuntimeException $runtimeException) {
                echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

                return 1;
            }

            $fixedCount = $this->fixViolations($architecture, $ruleViolationCollection);

            if ($fixedCount > 0) {
                $analysisResultCache->clear();

                $files                             = $analyser->filesForAnalysis($architecture, $scanPaths);
                $metadata                          = $analysisCacheMetadataFactory->metadata(
                    $basePath,
                    $configFile,
                    $scanPaths,
                    $files
                );
                $cacheKey                          = $analysisCacheMetadataFactory->key($metadata);
                $unfilteredRuleViolationCollection = $analyser->analyse(
                    $architecture,
                    $scanPaths,
                    null,
                    $analyserOptions,
                    $files
                );
                $analysisResultCache->store($cacheKey, $metadata, $unfilteredRuleViolationCollection);

                try {
                    $ruleViolationCollection = $this->resolveRuleViolationCollection(
                        $unfilteredRuleViolationCollection,
                        $architecture,
                        $basePath,
                        $shouldGenerateBaseline
                    );
                } catch (RuntimeException $runtimeException) {
                    echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

                    return 1;
                }

                $elapsed = microtime(true) - $start;
            }
        } else {
            try {
                $ruleViolationCollection = $this->resolveRuleViolationCollection(
                    $unfilteredRuleViolationCollection,
                    $architecture,
                    $basePath,
                    $shouldGenerateBaseline
                );
            } catch (RuntimeException $runtimeException) {
                echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

                return 1;
            }
        }

        if ($reportType === 'console' && $fixedCount > 0) {
            echo PHP_EOL . $this->fixedViolationMessage($fixedCount) . PHP_EOL;
        }

        if ($shouldGenerateBaseline) {
            try {
                $baseline->generate($unfilteredRuleViolationCollection, $options['generate-baseline'], $basePath);
            } catch (RuntimeException $runtimeException) {
                echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

                return 1;
            }

            echo sprintf(
                "Generated baseline [%s] with %d violation(s).\n",
                $options['generate-baseline'],
                $unfilteredRuleViolationCollection->count()
            );

            return 0;
        }

        $report = match ($reportType) {
            'json' => (new JsonReport())->render($ruleViolationCollection, $elapsed),
            default => (new ConsoleReport())->render($ruleViolationCollection, $elapsed),
        };

        echo $report;

        return $ruleViolationCollection->hasViolations() ? 1 : 0;
    }

    private function resolveRuleViolationCollection(
        RuleViolationCollection $unfilteredRuleViolationCollection,
        Architecture $architecture,
        string $basePath,
        bool $shouldGenerateBaseline
    ): RuleViolationCollection {
        if ($shouldGenerateBaseline) {
            return $unfilteredRuleViolationCollection;
        }

        return (new BaselineFilter())->apply($unfilteredRuleViolationCollection, $architecture, $basePath);
    }

    private function fixViolations(Architecture $architecture, RuleViolationCollection $ruleViolationCollection): int
    {
        $rules      = $architecture->getRules();
        $fixedCount = 0;

        foreach ($ruleViolationCollection as $ruleViolation) {
            $rule = $rules[$ruleViolation->ruleKey] ?? null;

            if (! $rule instanceof FixableInterface) {
                continue;
            }

            if ($rule->fix($ruleViolation)) {
                $fixedCount++;
            }
        }

        return $fixedCount;
    }

    private function fixedViolationMessage(int $fixedCount): string
    {
        $message = $fixedCount === 1
            ? '1 violation has been fixed.'
            : sprintf('%d violations have been fixed.', $fixedCount);

        return sprintf('%s  %s', ColorSupport::wrap('✓', '92', ColorSupport::detect()), $message);
    }
}
