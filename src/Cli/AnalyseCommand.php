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
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Composer\ComposerJsonProvider;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\GithubReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;
use Boundwize\StructArmed\Report\Reports\SummaryReport;
use Boundwize\StructArmed\Report\WarningLine;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\AbstractJsonRecastFixableRule;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\JsonRecastFixerProcessor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
use InvalidArgumentException;
use RuntimeException;

use function count;
use function explode;
use function getenv;
use function in_array;
use function is_dir;
use function is_file;
use function max;
use function microtime;
use function sprintf;
use function str_starts_with;

use const PHP_EOL;

/**
 * @phpstan-type CommandOptions array{
 *     report?: string,
 *     config?: string,
 *     basepath?: string,
 *     generate-baseline?: string,
 *     no-progress?: true,
 *     summary?: true,
 *     clear-cache?: true,
 *     no-cache?: true,
 *     disable-parallel?: true,
 *     fix?: true,
 *     baseline-info?: true
 * }
 * @phpstan-import-type BaselineInfo from Baseline
 */
final readonly class AnalyseCommand
{
    private const VALUE_OPTIONS = [
        '--report'            => 'report',
        '--config'            => 'config',
        '--basepath'          => 'basepath',
        '-d'                  => 'basepath',
        '--generate-baseline' => 'generate-baseline',
    ];

    private const FLAG_OPTIONS = [
        '--no-progress'      => 'no-progress',
        '--summary'          => 'summary',
        '--clear-cache'      => 'clear-cache',
        '--no-cache'         => 'no-cache',
        '--disable-parallel' => 'disable-parallel',
        '--fix'              => 'fix',
        '--baseline-info'    => 'baseline-info',
    ];

    /**
     * Upper bound on fix/re-analyse passes. Fixers converge naturally (each
     * pass removes or rewrites code), so this mainly guards against a fixer
     * that keeps reporting success without resolving its violation. A cascade
     * deeper than this cap (e.g. an unused-abstraction chain of more than
     * ten levels) needs another --fix invocation to finish.
     */
    private const MAX_FIX_PASSES = 10;

    public function __construct(
        private ?ProgressHandlerInterface $progressHandler = null,
        private Baseline $baseline = new Baseline(),
        private BaselineFilter $baselineFilter = new BaselineFilter()
    ) {
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

        if (! in_array($reportType, ['console', 'json', 'github', 'summary'], true)) {
            echo sprintf("Invalid report type: %s\n\n", $reportType);
            echo Usage::render();

            return 1;
        }

        if (isset($options['no-cache'], $options['clear-cache'])) {
            echo "Error: --no-cache and --clear-cache cannot be used together.\n";

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

        foreach ($scanPaths as $scanPath) {
            $fullScanPath = Path::resolve($scanPath, $basePath);

            if (is_dir($fullScanPath)) {
                continue;
            }

            if (! is_file($fullScanPath)) {
                echo sprintf("Error: path [%s] not found.\n", $scanPath);

                return 1;
            }

            if (! Path::isAnalysableFile($fullScanPath, $basePath)) {
                echo sprintf(
                    'Error: path [%s] is not analysable. '
                        . "Expected a directory, a .php file, or the project composer.json.\n",
                    $scanPath
                );

                return 1;
            }
        }

        try {
            $configFile   = $options['config'] ?? ConfigLoader::discover($workingDirectory, $basePath);
            $architecture = ConfigLoader::load($configFile);
        } catch (RuntimeException | InvalidArgumentException $exception) {
            return $this->reportError($exception);
        }

        $baseline               = $architecture->getBaseline();
        $missingBaselineWarning = $this->baselineFilter->missingWarning($baseline, $basePath);

        // A warning would break the json output, and left out it would hide
        // that the baseline is not applied, so the json report keeps the error.
        if ($reportType === 'json' && $missingBaselineWarning !== null && ! isset($options['generate-baseline'])) {
            echo sprintf("Error: Baseline file [%s] does not exist.\n", $baseline);

            return 1;
        }

        $start                        = microtime(true);
        $fileHashProvider             = new FileHashProvider();
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory($fileHashProvider);
        $analysisResultCache          = null;
        $analysisNodeCacheNamespace   = '';

        // Without the cache, no file is hashed and no cache entry is read or
        // written, which saves that work on a one-off run over a huge codebase.
        if (! isset($options['no-cache'])) {
            $configHash                 = $analysisCacheMetadataFactory->configHash(
                $configFile,
                $architecture->getImportedFiles()
            );
            $analysisResultCache        = new AnalysisResultCache(
                $basePath,
                $fileHashProvider,
                $architecture->getCacheDirectory(),
                $configHash,
                $analysisCacheMetadataFactory->composerGeneratedVersionHash()
            );
            $analysisNodeCacheNamespace = $analysisCacheMetadataFactory->analysisNodeCacheNamespace(
                $basePath,
                $configHash
            );

            if (isset($options['clear-cache']) || $analysisResultCache->shouldInvalidate()) {
                $analysisResultCache->clear();
            }
        }

        $analyser        = new Analyser($basePath, $analysisResultCache, $analysisNodeCacheNamespace);
        $files           = $analyser->filesForAnalysis($architecture, $scanPaths);
        $progress        = in_array($reportType, ['console', 'summary'], true) && ! isset($options['no-progress'])
            ? $this->progressHandler ?? new ConsoleProgressBar()
            : null;
        $analyserOptions = isset($options['disable-parallel']) ? AnalyserOptions::sequential() : null;
        $metadata        = [];
        $cacheKey        = '';

        $ruleViolationCollection = null;

        if ($analysisResultCache instanceof AnalysisResultCache) {
            $metadata                = $analysisCacheMetadataFactory->metadata(
                $basePath,
                $configFile,
                $scanPaths,
                $files
            );
            $cacheKey                = $analysisCacheMetadataFactory->key($metadata);
            $ruleViolationCollection = $analysisResultCache->load($cacheKey, $metadata);
        }

        if (! $ruleViolationCollection instanceof RuleViolationCollection) {
            $ruleViolationCollection = $analyser->analyse(
                $architecture,
                $scanPaths,
                $progress,
                $analyserOptions,
                $files
            );
            $analysisResultCache?->store($cacheKey, $metadata, $ruleViolationCollection);
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

        if (isset($options['fix']) && ! $ruleViolationCollection->isEmpty()) {
            // To clear the in-memory Composer configuration between fix passes
            // when the persistent analysis cache is disabled.
            $composerJsonProvider = new ComposerJsonProvider();
            // Removal fixers can cascade: deleting an unused child abstraction
            // may leave its parent unused, so fix and re-analyse until a pass
            // fixes nothing. The pass cap only guards against a fixer that
            // reports success without resolving its violation.
            for ($fixPass = 0; $fixPass < self::MAX_FIX_PASSES; $fixPass++) {
                $passFixedCount = $this->fixViolations($architecture, $ruleViolationCollection);

                if ($passFixedCount === 0) {
                    break;
                }

                $violationCountBeforePass = $ruleViolationCollection->count();
                if ($analysisResultCache instanceof AnalysisResultCache) {
                    $analysisResultCache->clear();
                } else {
                    // With --no-cache there is no result cache to drop the composer.json
                    // memo, so a fixer that rewrote composer.json would be re-analysed
                    // against its old contents.
                    $composerJsonProvider->clear();
                }

                $files                             = $analyser->filesForAnalysis($architecture, $scanPaths);
                $unfilteredRuleViolationCollection = $analyser->analyse(
                    $architecture,
                    $scanPaths,
                    null,
                    $analyserOptions,
                    $files
                );

                if ($analysisResultCache instanceof AnalysisResultCache) {
                    $metadata = $analysisCacheMetadataFactory->metadata(
                        $basePath,
                        $configFile,
                        $scanPaths,
                        $files
                    );
                    $analysisResultCache->store(
                        $analysisCacheMetadataFactory->key($metadata),
                        $metadata,
                        $unfilteredRuleViolationCollection
                    );
                }

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

                // One fix can resolve several violations at once (e.g. two
                // closures starting on the same line), and the later ones
                // then report nothing to fix. Count what the re-analysis shows
                // resolved, never less than the fixes that reported success.
                $fixedCount += max(
                    $passFixedCount,
                    $violationCountBeforePass - $ruleViolationCollection->count()
                );
                $elapsed     = microtime(true) - $start;

                if ($ruleViolationCollection->isEmpty()) {
                    break;
                }
            }
        }

        if ($reportType !== 'json' && $fixedCount > 0) {
            echo PHP_EOL . $this->fixedViolationMessage($fixedCount) . PHP_EOL;
        }

        if ($shouldGenerateBaseline) {
            try {
                $this->baseline->generate(
                    $unfilteredRuleViolationCollection,
                    $options['generate-baseline'],
                    $basePath
                );
            } catch (RuntimeException $runtimeException) {
                return $this->reportError($runtimeException);
            }

            $violationCount = $unfilteredRuleViolationCollection->count();

            echo sprintf(
                "Generated baseline [%s] with %d violation%s.\n",
                $options['generate-baseline'],
                $violationCount,
                $violationCount === 1 ? '' : 's'
            );

            return 0;
        }

        $withSummary = isset($options['summary']);
        // A missing baseline has nothing to tell beyond its own warning below.
        $baselineInfo = isset($options['baseline-info']) && $missingBaselineWarning === null
            ? $this->baseline->info(
                $baseline,
                $basePath,
                $unfilteredRuleViolationCollection->count() - $ruleViolationCollection->count(),
                $scanPaths === []
            )
            : null;

        echo match ($reportType) {
            'json' => (new JsonReport($withSummary, $baselineInfo))->render($ruleViolationCollection, $elapsed),
            // GitHub resolves annotation paths against the repository root, which
            // differs from the base path when a sub directory is analysed.
            'github' => (new GithubReport(getenv('GITHUB_WORKSPACE') ?: $basePath, $withSummary, $configFile))
                ->render($ruleViolationCollection, $elapsed),
            'summary' => (new SummaryReport($configFile))->render($ruleViolationCollection, $elapsed),
            default => (new ConsoleReport($withSummary, $configFile))->render($ruleViolationCollection, $elapsed),
        };

        // Shown after the report, so it stays visible below a long violation
        // list. The json report never gets here with a missing baseline.
        if ($missingBaselineWarning !== null) {
            echo PHP_EOL . WarningLine::render($missingBaselineWarning) . PHP_EOL;
        } elseif ($baselineInfo !== null && $reportType !== 'json') {
            // An extra line would break the json output: the json report carries it under a `baseline` key.
            echo PHP_EOL . $this->baselineInfoLine($baselineInfo) . PHP_EOL;
        }

        // The github report surfaces violations as pull request annotations,
        // so the job itself is not failed on top of them.
        if ($reportType === 'github') {
            return 0;
        }

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
            $argument       = $arguments[$index];
            $optionAndValue = explode('=', $argument, 2);
            $option         = $optionAndValue[0];
            $value          = $optionAndValue[1] ?? null;

            if (isset(self::VALUE_OPTIONS[$option])) {
                $value ??= $arguments[++$index] ?? '';

                $options[self::VALUE_OPTIONS[$option]] = $value;
                continue;
            }

            if (isset(self::FLAG_OPTIONS[$argument])) {
                $options[self::FLAG_OPTIONS[$argument]] = true;
                continue;
            }

            // `--help` only gets here as a file or directory of that name, which is a path to analyse.
            if (str_starts_with($argument, '--') && $argument !== '--help') {
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

        return $this->baselineFilter->apply($unfilteredRuleViolationCollection, $architecture, $basePath);
    }

    private function fixViolations(Architecture $architecture, RuleViolationCollection $ruleViolationCollection): int
    {
        $fixedCount = 0;
        // The violations were collected before any fix ran, so once a fix adds
        // or removes lines in a file, the lines of that file's remaining
        // violations are stale and could point a line-based fixer at other
        // code. Those are left to the next pass, which re-analyses the file.
        $lineShiftedFiles = [];

        foreach ($architecture->getRules() as $ruleKey => $rule) {
            if (! $rule instanceof FixableInterface) {
                continue;
            }

            $ruleViolations = $ruleViolationCollection->forRule($ruleKey);
            if ($ruleViolations === []) {
                continue;
            }

            if (
                ! $rule instanceof AbstractPhpParserFixableRule
                && ! $rule instanceof AbstractJsonRecastFixableRule
            ) {
                foreach ($ruleViolations as $ruleViolation) {
                    if (isset($lineShiftedFiles[$ruleViolation->file])) {
                        continue;
                    }

                    if ($rule->fix($ruleViolation)) {
                        $fixedCount++;
                        // A custom fixer does not tell whether it moved lines,
                        // so a successful one is assumed to have.
                        $lineShiftedFiles[$ruleViolation->file] = true;
                    }
                }

                continue;
            }

            $violationsByFile = [];

            foreach ($ruleViolations as $ruleViolation) {
                $violationsByFile[$ruleViolation->file][] = $ruleViolation;
            }

            foreach ($violationsByFile as $file => $violations) {
                if (isset($lineShiftedFiles[$file])) {
                    continue;
                }

                if ($rule->fix(...$violations)) {
                    $fixedCount += count($violations);

                    // A successful fix went through the rule's processor,
                    // which holds the answer of that very call.
                    $lineCountChanged = $rule instanceof AbstractPhpParserFixableRule
                        ? PhpParserFixerProcessor::$lastFixChangedLineCount
                        : JsonRecastFixerProcessor::$lastFixChangedLineCount;

                    if ($lineCountChanged) {
                        $lineShiftedFiles[$file] = true;
                    }
                }
            }
        }

        return $fixedCount;
    }

    private function reportError(RuntimeException|InvalidArgumentException $exception): int
    {
        echo 'Error: ' . $exception->getMessage() . PHP_EOL;

        return 1;
    }

    /**
     * @param BaselineInfo $baselineInfo
     */
    private function baselineInfoLine(array $baselineInfo): string
    {
        $line = $this->baseline->infoLine($baselineInfo);

        // Only a baseline that went stale is worth a warning.
        return $baselineInfo['no_longer_found'] > 0 ? WarningLine::render($line) : $line;
    }

    private function fixedViolationMessage(int $fixedCount): string
    {
        $message = $fixedCount === 1
            ? '1 violation has been fixed.'
            : sprintf('%d violations have been fixed.', $fixedCount);

        return sprintf('%s  %s', ColorSupport::wrap('✓', '92', ColorSupport::detect()), $message);
    }
}
