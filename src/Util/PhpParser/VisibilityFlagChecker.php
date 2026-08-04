<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Modifiers;

final class VisibilityFlagChecker
{
    public static function hasExplicitVisibilityFlag(int $flags): bool
    {
        return ($flags & Modifiers::VISIBILITY_MASK) !== 0;
    }
}
