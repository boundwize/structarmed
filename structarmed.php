<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;

return Architecture::define()
    ->layer('Analyser', 'src/Analyser/')
    ->layer('Baseline', 'src/Baseline/')
    ->layer('Cache', 'src/Cache/')
    ->layer('Cli', 'src/Cli/')
    ->layer('Composer', 'src/Composer/')
    ->layer('Config', 'src/Config/')
    ->layer('Core', [
        'src/Architecture.php',
        'src/Version.php',
    ])
    ->layer('Exception', 'src/Exception/')
    ->layer('LayerResolver', 'src/LayerResolver/')
    ->layer('PHPUnit', 'src/PHPUnit/')
    ->layer('Preset', 'src/Preset/')
    ->layer('Progress', 'src/Progress/')
    ->layer('Report', 'src/Report/')
    ->layer('Rule', 'src/Rule/')
    ->layer('Util', 'src/Util/')
    ->ruleset([
        'Analyser'      => ['+Cache', 'Composer', 'LayerResolver', 'Progress', 'Util'],
        'Baseline'      => ['Core', 'Rule', 'Util'],
        'Cache'         => ['Analyser', 'Core', 'Rule', 'Util'],
        'Cli'           => ['Baseline', '+Cache', 'Config', 'Progress', 'Report', 'Util'],
        'Composer'      => [],
        'Config'        => ['Core'],
        'Core'          => ['Exception', 'Preset', 'Rule'],
        'Exception'     => [],
        'LayerResolver' => ['Util'],
        'PHPUnit'       => ['Analyser', 'Baseline', 'Cache', 'Config', 'Exception', 'Progress', 'Report', 'Rule'],
        'Preset'        => ['Core', 'Rule'],
        'Progress'      => ['Cli'],
        'Report'        => ['Cli', 'Core', 'Rule'],
        'Rule'          => ['Analyser', 'Composer', 'Core', 'Util'],
        'Util'          => [],
    ])
    ->skip([
        'tests/Fixtures/',
        Psr1Preset::METHODS_MUST_BE_CAMEL_CASE                   => [
            __DIR__ . '/src/Preset/Preset.php',
        ],
        Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS => [
            __DIR__ . '/tests/Analyser/Parallel/ParallelClassNodeExtractorTest.php',
            __DIR__ . '/tests/Analyser/Parallel/MockFunctions.php',
        ],
    ])
    ->withPresets(Preset::PSR1(), Preset::PSR12(), Preset::PSR4());
