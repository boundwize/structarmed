<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use function getenv;
use function stream_isatty;

use const STDOUT;

final class ColorSupport
{
    /**
     * @param resource|null $stream
     */
    public static function detect(mixed $stream = null): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return stream_isatty($stream ?? STDOUT);
    }

    public static function wrap(string $value, string $code, bool $useColor): string
    {
        if (! $useColor || $value === '') {
            return $value;
        }

        return "\033[" . $code . 'm' . $value . "\033[0m";
    }
}
