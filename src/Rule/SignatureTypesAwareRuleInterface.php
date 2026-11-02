<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

/**
 * Marker for rules that read a ClassNode's $signatures. When at least one
 * active rule implements this marker, the analyser collects the class-like
 * types named by member signatures; otherwise $signatures stays empty.
 */
interface SignatureTypesAwareRuleInterface extends RuleInterface
{
}
