<?php

declare(strict_types=1);

use Boundwize\Pyrameter\Config\PyrameterConfig;

return PyrameterConfig::defaults()
    ->targetShape(
        unit: ['min' => 51],
        integration: ['max' => 49],
    )
    ->failOnViolation();
