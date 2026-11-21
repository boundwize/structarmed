<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

final readonly class HelpCommand
{
    public function run(): int
    {
        echo Usage::render();

        return 0;
    }
}
