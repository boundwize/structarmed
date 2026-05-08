<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report;

use Boundwize\StructArmed\Rule\RuleViolationCollection;

interface ReportInterface
{
    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string;
}
