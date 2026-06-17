<?php

declare(strict_types=1);

use Boundwize\Pyrameter\Config\PyrameterConfig;

return PyrameterConfig::defaults()
    ->targetShape(
        unit: ['min' => 50],
        integration: ['max' => 50],
    )
    ->failOnViolation();
