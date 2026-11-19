<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Report\SummaryTable;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Version;

use function count;
use function implode;
use function sprintf;
use function str_repeat;
use function strlen;

use const PHP_EOL;

/**
 * Renders the number of violations per rule instead of every violation, most
 * violated rule first, to show where a project stands at a glance.
 */
final class SummaryReport implements ReportInterface
{
    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        $heading = sprintf('StructArmed %s — Architecture Enforcement', Version::current());

        $lines = [
            '',
            $heading,
            str_repeat('=', strlen($heading)),
            '',
            ...SummaryTable::lines($ruleViolationCollection),
            $ruleViolationCollection->isEmpty()
                ? PHP_EOL . sprintf('✅  No violations found. (%.2fs)', $elapsedSeconds)
                : sprintf(
                    '%d violation(s) found in %d rule(s)  •  %.2fs',
                    $ruleViolationCollection->count(),
                    count($ruleViolationCollection->countByRule()),
                    $elapsedSeconds
                ),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }
}
