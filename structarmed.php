<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;

return Architecture::define()
    ->skip([
        'tests/Fixtures/',
        Psr1Preset::METHODS_MUST_BE_CAMEL_CASE => [
            __DIR__ . '/src/Preset/Preset.php',
        ],
    ])
    ->withPresets(Preset::PSR1(), Preset::PSR12(), Preset::PSR4());
