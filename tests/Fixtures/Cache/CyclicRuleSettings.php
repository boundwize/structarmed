<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Fixtures\Cache;

final readonly class CyclicRuleSettings
{
    public self $next;

    public function __construct(public int $maximum)
    {
        $this->next = $this;
    }
}
