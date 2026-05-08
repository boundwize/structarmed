<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\PHPUnit;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Exception\ViolationsFoundException;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

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
        $analyser     = new Analyser($basePath);

        $start      = microtime(true);
        $violations = $analyser->analyse($architecture);
        $elapsed    = microtime(true) - $start;

        $report = (new ConsoleReport())->render($violations, $elapsed);
        echo $report;

        if ($violations->hasViolations()) {
            throw new ViolationsFoundException(sprintf(
                'StructArmed found %d architecture violation(s). Fix them before running tests.',
                $violations->count()
            ));
        }
    }
}
