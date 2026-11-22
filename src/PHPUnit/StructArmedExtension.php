<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\PHPUnit;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Baseline\Baseline;
use Boundwize\StructArmed\Baseline\BaselineFilter;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\WarningLine;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function filter_var;
use function getcwd;
use function getenv;
use function microtime;
use function sprintf;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;

final class StructArmedExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters
    ): void {
        if (getenv('STRUCTARMED_DISABLED') === '1') {
            return;
        }

        $cwd        = getcwd();
        $basePath   = $cwd !== false ? $cwd : '';
        $configFile = $parameters->has('config')
            ? $parameters->get('config')
            : ConfigLoader::discover($basePath);

        $architecture = ConfigLoader::load($configFile);

        $start                        = microtime(true);
        $fileHashProvider             = new FileHashProvider();
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory($fileHashProvider);
        $analysisResultCache          = null;
        $analysisNodeCacheNamespace   = '';

        // Without the cache, no file is hashed and no cache entry is read or
        // written, the same as the --no-cache option of the analyse command.
        if (! $this->isCacheDisabled($parameters)) {
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

            if ($analysisResultCache->shouldInvalidate()) {
                $analysisResultCache->clear();
            }
        }

        $analyser = new Analyser($basePath, $analysisResultCache, $analysisNodeCacheNamespace);
        $files    = $analyser->filesForAnalysis($architecture);
        $metadata = [];
        $cacheKey = '';

        $ruleViolationCollection = null;

        if ($analysisResultCache instanceof AnalysisResultCache) {
            $metadata                = $analysisCacheMetadataFactory->metadata($basePath, $configFile, [], $files);
            $cacheKey                = $analysisCacheMetadataFactory->key($metadata);
            $ruleViolationCollection = $analysisResultCache->load($cacheKey, $metadata);
        }

        if (! $ruleViolationCollection instanceof RuleViolationCollection) {
            $progressHandler = $this->isProgressEnabled($configuration, $parameters) ? new ConsoleProgressBar() : null;

            $ruleViolationCollection = $analyser->analyse(
                $architecture,
                progressHandler: $progressHandler,
                files: $files
            );
            $analysisResultCache?->store($cacheKey, $metadata, $ruleViolationCollection);
        }

        $elapsed = microtime(true) - $start;

        $unfilteredCount         = $ruleViolationCollection->count();
        $ruleViolationCollection = (new BaselineFilter())->apply($ruleViolationCollection, $architecture, $basePath);

        $report = (new ConsoleReport(configFile: $configFile))->render($ruleViolationCollection, $elapsed);
        echo $report . "\n";

        $baselinePath           = $architecture->getBaseline();
        $missingBaselineWarning = (new BaselineFilter())->missingWarning($baselinePath, $basePath);

        // The blank line after either line keeps it apart from the PHPUnit output that follows.
        if ($missingBaselineWarning !== null) {
            echo WarningLine::render($missingBaselineWarning) . "\n\n";
        } elseif ($this->isBaselineInfoEnabled($parameters)) {
            // Same line as the --baseline-info option of the analyse command.
            $baseline     = new Baseline();
            $baselineInfo = $baseline->info(
                $baselinePath,
                $basePath,
                $unfilteredCount - $ruleViolationCollection->count()
            );
            $line         = $baseline->infoLine($baselineInfo);

            // Only a baseline that went stale is worth a warning.
            echo ($baselineInfo['no_longer_found'] > 0 ? WarningLine::render($line) : $line) . "\n\n";
        }

        if ($ruleViolationCollection->hasViolations()) {
            throw new ViolationsFoundException(sprintf(
                'StructArmed found %d architecture violation(s). Fix them before running tests.',
                $ruleViolationCollection->count()
            ));
        }
    }

    /**
     * The extension's "baseline-info" bootstrap parameter prints how the baseline
     * matches the analysis; a missing or non boolean value leaves the line out.
     */
    private function isBaselineInfoEnabled(ParameterCollection $parameterCollection): bool
    {
        return $parameterCollection->has('baseline-info')
            && filter_var($parameterCollection->get('baseline-info'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The extension's "no-cache" bootstrap parameter disables the cache;
     * a missing or non boolean value keeps it enabled.
     */
    private function isCacheDisabled(ParameterCollection $parameterCollection): bool
    {
        return $parameterCollection->has('no-cache')
            && filter_var($parameterCollection->get('no-cache'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * PHPUnit's own --no-progress flag wins; otherwise the extension's
     * "progress" bootstrap parameter decides, defaulting to enabled.
     */
    private function isProgressEnabled(Configuration $configuration, ParameterCollection $parameterCollection): bool
    {
        if ($configuration->noProgress()) {
            return false;
        }

        if (! $parameterCollection->has('progress')) {
            return true;
        }

        return filter_var(
            $parameterCollection->get('progress'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true;
    }
}
