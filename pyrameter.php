<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\Analyser;
use Pyrameter\Config\PyrameterConfig;
use Pyrameter\TestKind;

return PyrameterConfig::defaults()
    ->usesClass(Analyser::class, TestKind::Integration)
    ->targetShape(
        unit: ['min' => 85],
        integration: ['max' => 15],
    );