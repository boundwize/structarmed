<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\CollectorState;

/**
 * State of an active method. Branches in nested methods also increment the
 * enclosing method's complexity; name and static flag resolve parent calls.
 *
 * @internal
 */
final class MethodAnalysis
{
    public int $cyclomaticComplexity = 1;

    public function __construct(
        /** Lowercased method name. */
        public readonly string $name,
        public readonly bool $isStatic,
    ) {
    }
}
