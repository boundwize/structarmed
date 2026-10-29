<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Fixtures\Cache;

use Closure;

final readonly class RuleSettings
{
    public function __construct(public int $maximum)
    {
    }

    public function callback(): Closure
    {
        return fn(): int => $this->maximum;
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        // A serializer would discard the option; fingerprinting must read stored values.
        return [];
    }
}
