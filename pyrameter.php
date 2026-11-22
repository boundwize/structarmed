<?php

declare(strict_types=1);

use Boundwize\Pyrameter\Config\PyrameterConfig;

return PyrameterConfig::defaults()
    ->targetShape(
        unit: ['min' => 52],
        integration: ['max' => 48],
    )
    ->failOnViolation();
