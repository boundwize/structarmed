<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Cli\ColorSupport;
use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Version;

use function implode;
use function sprintf;
use function str_repeat;
use function strlen;

use const PHP_EOL;

final class ConsoleReport implements ReportInterface
{
    public function render(RuleViolationCollection $ruleViolationCollection, float $elapsedSeconds): string
    {
        $useColor  = ColorSupport::detect();
        $lines     = [];
        $heading   = sprintf('StructArmed %s — Architecture Enforcement', Version::current());
        $lineWidth = strlen($heading);

        $lines[] = '';
        $lines[] = $heading;
        $lines[] = str_repeat('=', $lineWidth);

        if ($ruleViolationCollection->isEmpty()) {
            $lines[] = '';
            $lines[] = sprintf('✅  No violations found. (%.2fs)', $elapsedSeconds);
            $lines[] = '';

            return implode(PHP_EOL, $lines);
        }

        $lines[] = '';
        $lines[] = sprintf('Found %d violation(s):', $ruleViolationCollection->count());
        $lines[] = str_repeat('─', $lineWidth);

        foreach ($ruleViolationCollection as $violation) {
            $lines[] = '';
            $lines[] = sprintf('%s  [%s]', ColorSupport::wrap('✗', '91', $useColor), $violation->ruleKey);
            $lines[] = sprintf('   %s', $violation->message);
            $lines[] = sprintf('   → %s:%d', $violation->file, $violation->line);

            if ($violation->layer !== null) {
                $lines[] = sprintf('   Layer: %s', $violation->layer);
            }

            if ($violation->fixable) {
                $lines[] = '   Hint: run again with --fix to automatically fix this violation.';
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('─', $lineWidth);
        $lines[] = sprintf(
            '%d violation(s) found  •  %.2fs',
            $ruleViolationCollection->count(),
            $elapsedSeconds
        );
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
