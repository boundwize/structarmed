<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRETTY_PRINT;

final readonly class JsonReport implements ReportInterface
{
    public function __construct(private bool $withSummary = false)
    {
    }

    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        $data = ['violations' => $ruleViolationCollection->toArray()];

        if ($this->withSummary) {
            $data['summary'] = (object) $ruleViolationCollection->countByRule();
        }

        $data['total']   = $ruleViolationCollection->count();
        $data['passed']  = $ruleViolationCollection->isEmpty();
        $data['elapsed'] = $elapsedSeconds;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
