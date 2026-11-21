<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Report;

use Boundwize\StructArmed\Rule\RuleViolationCollection;

use function array_keys;
use function array_map;
use function max;
use function str_pad;
use function str_repeat;
use function strlen;
use function strval;

use const STR_PAD_LEFT;

/**
 * The number of violations per rule as table lines, most violated rule first.
 * Without violations the table holds a single "(none)" row.
 */
final class SummaryTable
{
    private const RULE_HEADING = 'Rule';

    private const COUNT_HEADING = 'Count';

    private const NO_RULE = '(none)';

    /** @return list<string> */
    public static function lines(RuleViolationCollection $ruleViolationCollection): array
    {
        // A passing result still renders a table, with a single zero row.
        $countByRule = $ruleViolationCollection->countByRule() ?: [self::NO_RULE => 0];
        $ruleKeys    = array_map(strval(...), array_keys($countByRule));
        $ruleWidth   = max(strlen(self::RULE_HEADING), ...array_map(strlen(...), $ruleKeys));
        $countWidth  = max(strlen(self::COUNT_HEADING), strlen((string) $ruleViolationCollection->count()));
        $separator   = str_repeat('─', $ruleWidth + 2 + $countWidth);

        $lines = [
            str_pad(self::RULE_HEADING, $ruleWidth)
                . '  '
                . str_pad(self::COUNT_HEADING, $countWidth, ' ', STR_PAD_LEFT),
            $separator,
        ];

        foreach ($countByRule as $ruleKey => $count) {
            $lines[] = str_pad((string) $ruleKey, $ruleWidth)
                . '  '
                . str_pad((string) $count, $countWidth, ' ', STR_PAD_LEFT);
        }

        $lines[] = $separator;

        return $lines;
    }
}
