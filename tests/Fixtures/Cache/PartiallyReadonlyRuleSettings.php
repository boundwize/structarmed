<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Fixtures\Cache;

final class PartiallyReadonlyRuleSettings
{
    public int $runtimeCounter = 0;

    public function __construct(public readonly int $maximum)
    {
    }
}
