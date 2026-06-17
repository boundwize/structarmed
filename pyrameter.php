<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\Pyrameter\Config\PyrameterConfig;
use Boundwize\Pyrameter\TestKind;

return PyrameterConfig::defaults()
    ->usesClass(Analyser::class, TestKind::Integration)
    ->targetShape(
        unit: ['min' => 35],
        integration: ['max' => 65],
    )
    ->failOnViolation();
