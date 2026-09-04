<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

final class Usage
{
    public static function render(): string
    {
        return <<<'TXT'
Usage:
  structarmed --version
  structarmed init [--preset=ddd|mvc|psr4|psr1|psr12|per|psr15|yagni|codequality|all]
  structarmed analyse|analyze [path ...] [--config=path/to/structarmed.php]
    [--report=console|json] [--no-progress] [--clear-cache] [--disable-parallel]
    [--fix] [--generate-baseline=structarmed-baseline.php]
  structarmed --clear-cache [--config=path/to/structarmed.php]

TXT;
    }
}
