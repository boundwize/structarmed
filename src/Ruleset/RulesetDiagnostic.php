<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Ruleset;

final readonly class RulesetDiagnostic
{
    /** The configuration is invalid: the entry can never take effect. */
    public const ERROR = 'ERROR';

    /** The entry has no effect, so it can go without changing which layers are allowed. */
    public const WARNING = 'WARNING';

    /**
     * @param self::ERROR|self::WARNING $severity
     * @param string $layer The ruleset key the issue is found under.
     * @param string $entry The entry of that layer as the ruleset declares it, such as `+Cache`;
     *                      empty when the issue is the ruleset key itself.
     * @param string $fix   What to change in the ruleset.
     */
    public function __construct(
        public string $severity,
        public string $layer,
        public string $entry,
        public string $issue,
        public string $fix,
    ) {
    }
}
