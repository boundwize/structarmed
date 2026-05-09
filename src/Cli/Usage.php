<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

final class Usage
{
    public static function render(): string
    {
        return <<<'TXT'
Usage:
  structarmed init [--preset=ddd|mvc|psr4|all]
  structarmed analyse|analyze [path ...] [--config=path/to/structarmed.php] [--report=console|json] [--no-progress]

TXT;
    }
}
