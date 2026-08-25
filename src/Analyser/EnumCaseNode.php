<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class EnumCaseNode
{
    /**
     * @param int|string|null $value Statically resolved backing value of the case. Null for
     *                               a pure enum case, and also for a backed case whose value
     *                               the analyser cannot evaluate (a global or class constant
     *                               expression, for instance) — use ClassNode::isBackedEnum()
     *                               to tell whether the case is backed at all.
     */
    public function __construct(
        public string $name,
        public int $line = 0,
        public int|string|null $value = null,
    ) {
    }

    /**
     * Whether the analyser could statically resolve the backing value. False
     * does not imply a pure enum case; see {@see $value}.
     */
    public function hasResolvedValue(): bool
    {
        return $this->value !== null;
    }
}
