<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\PHPUnit;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Baseline\BaselineFilter;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function filter_var;
use function getcwd;
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
        $cwd        = getcwd();
        $basePath   = $cwd !== false ? $cwd : '';
        $configFile = $parameters->has('config')
            ? $parameters->get('config')
            : ConfigLoader::discover($basePath);

        $architecture = ConfigLoader::load($configFile);

        $analysisResultCache          = new AnalysisResultCache($basePath, $architecture->getCacheDirectory());
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configHash                   = $analysisCacheMetadataFactory->fileHash($configFile);
        $composerGeneratedVersionHash = $analysisCacheMetadataFactory->composerGeneratedVersionHash();

        if (
            $analysisResultCache->hasDifferentConfig($configHash)
            || $analysisResultCache->hasDifferentComposerGeneratedVersion($composerGeneratedVersionHash)
        ) {
            $analysisResultCache->clear();
        }

        $analyser = new Analyser($basePath, $analysisResultCache, $configHash);

        $files    = $analyser->filesForAnalysis($architecture);
        $metadata = $analysisCacheMetadataFactory->metadata($basePath, $configFile, [], $files);
        $cacheKey = $analysisCacheMetadataFactory->key($metadata);

        $start                   = microtime(true);
        $ruleViolationCollection = $analysisResultCache->load($cacheKey, $metadata);

        if (! $ruleViolationCollection instanceof RuleViolationCollection) {
            $progressHandler = $this->isProgressEnabled($parameters) ? new ConsoleProgressBar() : null;

            $ruleViolationCollection = $analyser->analyse(
                $architecture,
                progressHandler: $progressHandler
            );
            $analysisResultCache->store($cacheKey, $metadata, $ruleViolationCollection);
        }

        $elapsed = microtime(true) - $start;

        $ruleViolationCollection = (new BaselineFilter())->apply($ruleViolationCollection, $architecture, $basePath);

        $report = (new ConsoleReport())->render($ruleViolationCollection, $elapsed);
        echo $report . "\n";

        if ($ruleViolationCollection->hasViolations()) {
            throw new ViolationsFoundException(sprintf(
                'StructArmed found %d architecture violation(s). Fix them before running tests.',
                $ruleViolationCollection->count()
            ));
        }
    }

    private function isProgressEnabled(ParameterCollection $parameterCollection): bool
    {
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
