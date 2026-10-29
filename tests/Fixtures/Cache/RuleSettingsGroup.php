<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Fixtures\Cache;

final readonly class RuleSettingsGroup
{
    /** @param list<RuleSettings> $settings */
    public function __construct(public array $settings)
    {
    }
}
