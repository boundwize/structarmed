<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\PHPUnit;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function getcwd;
use function microtime;
use function sprintf;

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

        $start                   = microtime(true);
        $ruleViolationCollection = $analyser->analyse($architecture);
        $elapsed                 = microtime(true) - $start;

        $report = (new ConsoleReport())->render($ruleViolationCollection, $elapsed);
        echo $report;

        if ($ruleViolationCollection->hasViolations()) {
            throw new ViolationsFoundException(sprintf(
                'StructArmed found %d architecture violation(s). Fix them before running tests.',
                $ruleViolationCollection->count()
            ));
        }
    }
}
