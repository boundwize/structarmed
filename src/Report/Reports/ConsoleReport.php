<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report\Reports;

use Boundwize\StructArmed\Report\ReportInterface;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

final class ConsoleReport implements ReportInterface
{
    public function render(RuleViolationCollection $violations, float $elapsedSeconds): string
    {
        $lines = [];

        $lines[] = '';
        $lines[] = 'StructArmed — Architecture Enforcement';
        $lines[] = str_repeat('=', 42);

        if ($violations->isEmpty()) {
            $lines[] = '';
            $lines[] = sprintf('✅  No violations found. (%.2fs)', $elapsedSeconds);
            $lines[] = '';

            return implode(PHP_EOL, $lines);
        }

        $lines[] = '';
        $lines[] = sprintf('Found %d violation(s):', $violations->count());
        $lines[] = str_repeat('─', 42);

        foreach ($violations as $violation) {
            $lines[] = '';
            $lines[] = sprintf('✗  [%s]', $violation->ruleKey);
            $lines[] = sprintf('   %s', $violation->message);
            $lines[] = sprintf('   → %s:%d', $violation->file, $violation->line);

            if ($violation->layer !== null) {
                $lines[] = sprintf('   Layer: %s', $violation->layer);
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('─', 42);
        $lines[] = sprintf(
            '%d violation(s) found  •  %.2fs',
            $violations->count(),
            $elapsedSeconds
        );
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
