<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\Pyrameter\Config\PyrameterConfig;
use Boundwize\Pyrameter\TestKind;

return PyrameterConfig::defaults()
    ->usesClass(Analyser::class, TestKind::Integration)
    ->targetShape(
        unit: ['min' => 30],
        integration: ['max' => 70],
    )
    ->failOnViolation();
