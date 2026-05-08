<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset;

use Boundwize\StructArmed\Architecture;

interface PresetInterface
{
    /**
     * Apply this preset's rules to the given Architecture instance.
     */
    public function apply(Architecture $architecture): void;
}
