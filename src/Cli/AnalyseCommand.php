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
use function explode;
use function in_array;
use function is_dir;
use function is_file;
use function microtime;
use function sprintf;
use function str_starts_with;

use const PHP_EOL;

/**
 * @phpstan-type CommandOptions array{
 *     report?: string,
 *     config?: string,
 *     generate-baseline?: string,
 *     no-progress?: true,
 *     clear-cache?: true,
 *     disable-parallel?: true,
 *     fix?: true
 * }
 */
final readonly class AnalyseCommand
{
    private const VALUE_OPTIONS = [
        '--report'            => 'report',
        '--config'            => 'config',
        '--generate-baseline' => 'generate-baseline',
    ];

    private const FLAG_OPTIONS = [
        '--no-progress'      => 'no-progress',
        '--clear-cache'      => 'clear-cache',
        '--disable-parallel' => 'disable-parallel',
        '--fix'              => 'fix',
    ];

    public function __construct(private ?ProgressHandlerInterface $progressHandler = null)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $parsedArguments = $this->parseArguments($arguments);

        if ($parsedArguments === null) {
            return 1;
        }

        [$options, $scanPaths] = $parsedArguments;
        $reportType            = $options['report'] ?? 'console';

        if (! in_array($reportType, ['console', 'json'], true)) {
            echo sprintf("Invalid report type: %s\n\n", $reportType);
            echo Usage::render();

            return 1;
        }

        foreach ($scanPaths as $scanPath) {
            $fullScanPath = Path::resolve($scanPath, $basePath);

            if (
                is_dir($fullScanPath)
                || (is_file($fullScanPath) && Path::isAnalysableFile($fullScanPath, $basePath))
            ) {
                continue;
            }

            echo sprintf("Error: path [%s] not found.\n", $scanPath);

            return 1;
        }

        try {
            $configFile   = $options['config'] ?? ConfigLoader::discover($basePath);
            $architecture = ConfigLoader::load($configFile);
        } catch (RuntimeException $runtimeException) {
            return $this->reportError($runtimeException);
        }

        $start                        = microtime(true);
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configHash                   = $analysisCacheMetadataFactory->fileHash($configFile);
        $composerGeneratedVersionHash = $analysisCacheMetadataFactory->composerGeneratedVersionHash();
        $analysisResultCache          = new AnalysisResultCache(
            $basePath,
            $architecture->getCacheDirectory(),
            $configHash,
            $composerGeneratedVersionHash
        );
        $classNodeCacheNamespace      = $analysisCacheMetadataFactory->classNodeCacheNamespace($basePath, $configHash);
        $analyser                     = new Analyser($basePath, $analysisResultCache, $classNodeCacheNamespace);

        if (isset($options['clear-cache']) || $analysisResultCache->shouldInvalidate()) {
            $analysisResultCache->clear();
        }

        $files           = $analyser->filesForAnalysis($architecture, $scanPaths);
        $metadata        = $analysisCacheMetadataFactory->metadata($basePath, $configFile, $scanPaths, $files);
        $cacheKey        = $analysisCacheMetadataFactory->key($metadata);
        $progress        = $reportType === 'console' && ! isset($options['no-progress'])
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
        $unfilteredRuleViolationCollection = $ruleViolationCollection;
        $shouldGenerateBaseline            = isset($options['generate-baseline']);
        $fixedCount                        = 0;

        try {
            $ruleViolationCollection = $this->resolveRuleViolationCollection(
                $unfilteredRuleViolationCollection,
                $architecture,
                $basePath,
                $shouldGenerateBaseline
            );
        } catch (RuntimeException $runtimeException) {
            return $this->reportError($runtimeException);
        }

        if (isset($options['fix'])) {
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
                    return $this->reportError($runtimeException);
                }

                $elapsed = microtime(true) - $start;
            }
        }

        if ($reportType === 'console' && $fixedCount > 0) {
            echo PHP_EOL . $this->fixedViolationMessage($fixedCount) . PHP_EOL;
        }

        if ($shouldGenerateBaseline) {
            try {
                (new Baseline())->generate(
                    $unfilteredRuleViolationCollection,
                    $options['generate-baseline'],
                    $basePath
                );
            } catch (RuntimeException $runtimeException) {
                return $this->reportError($runtimeException);
            }

            echo sprintf(
                "Generated baseline [%s] with %d violation(s).\n",
                $options['generate-baseline'],
                $unfilteredRuleViolationCollection->count()
            );

            return 0;
        }

        echo match ($reportType) {
            'json' => (new JsonReport())->render($ruleViolationCollection, $elapsed),
            default => (new ConsoleReport())->render($ruleViolationCollection, $elapsed),
        };

        return $ruleViolationCollection->hasViolations() ? 1 : 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{CommandOptions, list<string>}|null
     */
    private function parseArguments(array $arguments): ?array
    {
        /** @var CommandOptions $options */
        $options   = [];
        $scanPaths = [];
        $counter   = count($arguments);

        for ($index = 0; $index < $counter; $index++) {
            $argument         = $arguments[$index];
            [$option, $value] = explode('=', $argument, 2) + [1 => null];

            if (isset(self::VALUE_OPTIONS[$option])) {
                if ($value === null) {
                    $value = $arguments[++$index] ?? '';
                }

                $options[self::VALUE_OPTIONS[$option]] = $value;
                continue;
            }

            if (isset(self::FLAG_OPTIONS[$argument])) {
                $options[self::FLAG_OPTIONS[$argument]] = true;
                continue;
            }

            if (str_starts_with($argument, '--')) {
                echo sprintf("Unknown option: %s\n\n", $argument);
                echo Usage::render();

                return null;
            }

            $scanPaths[] = $argument;
        }

        return [$options, $scanPaths];
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

            if ($rule instanceof FixableInterface && $rule->fix($ruleViolation)) {
                $fixedCount++;
            }
        }

        return $fixedCount;
    }

    private function reportError(RuntimeException $runtimeException): int
    {
        echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

        return 1;
    }

    private function fixedViolationMessage(int $fixedCount): string
    {
        $message = $fixedCount === 1
            ? '1 violation has been fixed.'
            : sprintf('%d violations have been fixed.', $fixedCount);

        return sprintf('%s  %s', ColorSupport::wrap('✓', '92', ColorSupport::detect()), $message);
    }
}
