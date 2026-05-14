<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

use function json_encode;

use const JSON_PRETTY_PRINT;

final class JsonReport implements ReportInterface
{
    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        return json_encode([
            'violations' => $ruleViolationCollection->toArray(),
            'total'      => $ruleViolationCollection->count(),
            'passed'     => $ruleViolationCollection->isEmpty(),
            'elapsed'    => $elapsedSeconds,
        ], JSON_PRETTY_PRINT) . "\n";
    }
}
