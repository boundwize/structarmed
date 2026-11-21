<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Controller', 'src/Controller/')
    ->layer('Model', 'src/Model/');
