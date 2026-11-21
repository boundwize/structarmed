<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

// Controller reaches Model through Service too, yet a plain entry does not pass
// on its allowed layers: Controller needs its own 'Model' entry, so no issue.
return Architecture::define()
    ->layer('Controller', 'src/Controller/')
    ->layer('Service', 'src/Service/')
    ->layerPattern('Repository', '/^App\\\\.*Repository$/')
    ->layerPattern('Model', '/^App\\\\Model\\\\.*$/')
    ->ruleset([
        'Controller' => ['Service', 'Model'],
        'Service'    => ['Repository', 'Model'],
        'Repository' => ['Model'],
        'Model'      => [],
    ]);
