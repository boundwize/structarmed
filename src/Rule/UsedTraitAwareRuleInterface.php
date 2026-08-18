<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

/**
 * Marker for rules whose evaluation depends on whether a trait is used by
 * another scanned class-like (class, trait, or enum). When at least one active
 * rule implements this marker, the analyser flags each ClassNode's $isUsed
 * before rules are evaluated, so implementers can read $classNode->isUsed.
 *
 * Trade-off: only usage within the scanned paths is known. A trait used solely
 * by a consumer outside the scan is reported as if not used.
 */
interface UsedTraitAwareRuleInterface extends RuleInterface
{
}
