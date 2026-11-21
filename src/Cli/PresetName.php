<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

final class PresetName
{
    /**
     * Names accepted by --preset besides `all`, in the order `all` applies them.
     * Each name upper-cased is its Preset factory method: psr4 → Preset::PSR4().
     */
    public const ALL = [
        'psr4',
        'psr1',
        'psr12',
        'per',
        'psr15',
        'ddd',
        'mvc',
        'yagni',
        'codequality',
        'stability',
        'enum',
    ];
}
