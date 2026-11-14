<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Modifiers;

final class VisibilityFlagChecker
{
    /**
     * A set-visibility alone (`private(set) string $name`) is PHP's shorthand
     * for `public private(set)`, so it declares the visibility as well.
     */
    public static function hasExplicitVisibilityFlag(int $flags): bool
    {
        return ($flags & (Modifiers::VISIBILITY_MASK | Modifiers::VISIBILITY_SET_MASK)) !== 0;
    }
}
