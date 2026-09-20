<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Modifiers;

final class ProtectedToPrivateFlags
{
    /**
     * Swaps `protected` for `private`, keeping the other modifiers except
     * `final`: `final private const` is a compile error and `final private
     * function` raises a compile warning, as a private member is never
     * overridden.
     *
     * Null when the flags are not `protected`, so there is nothing to change.
     */
    public static function tryFrom(int $flags): ?int
    {
        if (($flags & Modifiers::PROTECTED) === 0) {
            return null;
        }

        return ($flags & ~Modifiers::PROTECTED & ~Modifiers::FINAL) | Modifiers::PRIVATE;
    }
}
