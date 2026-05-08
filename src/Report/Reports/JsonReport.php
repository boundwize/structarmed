<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

final class JsonReport implements ReportInterface
{
    public function render(RuleViolationCollection $violations, float $elapsedSeconds): string
    {
        return (string) json_encode([
            'violations' => $violations->toArray(),
            'total'      => $violations->count(),
            'passed'     => $violations->isEmpty(),
            'elapsed'    => $elapsedSeconds,
        ], JSON_PRETTY_PRINT);
    }
}
