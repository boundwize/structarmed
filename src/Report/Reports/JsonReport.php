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
    /**
     * @param array{file: ?string, filtered_out: int, no_longer_found: ?int}|null $baselineInfo
     */
    public function __construct(private bool $withSummary = false, private ?array $baselineInfo = null)
    {
    }

    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        $data = ['violations' => $ruleViolationCollection->toArray()];

        if ($this->withSummary) {
            $data['summary'] = (object) $ruleViolationCollection->countByRule();
        }

        if ($this->baselineInfo !== null) {
            $data['baseline'] = $this->baselineInfo;
        }

        $data['total']   = $ruleViolationCollection->count();
        $data['passed']  = $ruleViolationCollection->isEmpty();
        $data['elapsed'] = $elapsedSeconds;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
