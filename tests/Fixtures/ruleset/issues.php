<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

// 'Repository' is never defined as a layer, which makes this ruleset invalid.
return Architecture::define()
    ->layer('Controller', 'src/Controller/')
    ->layer('Service', 'src/Service/')
    ->layer('Model', 'src/Model/')
    ->ruleset([
        'Controller' => ['Service', 'Model', 'Service'],
        'Service'    => ['Service', 'Model', 'Repository'],
        'Model'      => [],
    ]);
