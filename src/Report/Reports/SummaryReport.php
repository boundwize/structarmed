<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Report\SummaryTable;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
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
final readonly class SummaryReport implements ReportInterface
{
    public function __construct(private ?string $configFile = null)
    {
    }

    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        $heading        = sprintf('StructArmed %s — Architecture Enforcement', Version::current());
        $violationCount = $ruleViolationCollection->count();
        $ruleCount      = count($ruleViolationCollection->countByRule());

        $lines = [
            '',
            $heading,
            str_repeat('=', strlen($heading)),
            // A relative config path is printed as the absolute path in effect.
            ...$this->configFile !== null
                ? ['Configuration: ' . Path::normalise($this->configFile, canonicalise: true)]
                : [],
            '',
            ...SummaryTable::lines($ruleViolationCollection),
            $ruleViolationCollection->isEmpty()
                ? PHP_EOL . sprintf('✅  No violations found. (%.2fs)', $elapsedSeconds)
                : sprintf(
                    '%d violation%s found in %d rule%s  •  %.2fs',
                    $violationCount,
                    $violationCount === 1 ? '' : 's',
                    $ruleCount,
                    $ruleCount === 1 ? '' : 's',
                    $elapsedSeconds
                ),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }
}
