<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->skip([
        'tests/Fixtures/',
    ])
    ->withPreset(Preset::PSR4());
