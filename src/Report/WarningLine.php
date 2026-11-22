<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report;

use Boundwize\StructArmed\Cli\ColorSupport;

use function sprintf;

/**
 * A warning printed next to a report, its label yellow when colors are supported.
 */
final class WarningLine
{
    public static function render(string $message): string
    {
        return sprintf('%s %s', ColorSupport::wrap('Warning:', '33', ColorSupport::detect()), $message);
    }
}
