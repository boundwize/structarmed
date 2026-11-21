<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

// 'Composer' is listed by Analyser although '+Cache' already inherits it from Cache.
return Architecture::define()
    ->layer('Analyser', 'src/Analyser/')
    ->layer('Cache', 'src/Cache/')
    ->layer('Composer', 'src/Composer/')
    ->layer('Core', ['src/Architecture.php', 'src/Version.php'])
    ->layer('File', 'src/File/')
    ->layer('LayerResolver', 'src/LayerResolver/')
    ->layer('Progress', 'src/Progress/')
    ->layer('Rule', 'src/Rule/')
    ->layer('Util', 'src/Util/')
    ->ruleset([
        'Analyser' => ['+Cache', 'Composer', 'File', 'LayerResolver', 'Progress'],
        'Cache'    => ['Analyser', 'Composer', 'Core', 'Rule', 'Util'],
        'Composer' => ['Util'],
    ]);
