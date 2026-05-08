<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->layer('Domain', 'tests/Fixtures/sample/src/Domain/')
    ->layer('Application', 'tests/Fixtures/sample/src/Application/')
    ->layer('Infrastructure', 'tests/Fixtures/sample/src/Infrastructure/')
    ->withPreset(Preset::DDD());
