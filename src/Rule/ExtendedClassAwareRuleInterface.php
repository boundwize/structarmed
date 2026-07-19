<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

/**
 * Marker for rules whose evaluation depends on whether a class is extended by
 * another scanned class. When at least one active rule implements this marker,
 * the analyser flags each ClassNode's $isExtended (from its recursive parents)
 * before rules are evaluated, so implementers can read $classNode->isExtended.
 *
 * Trade-off: only classes extended within the scanned paths are known. A class
 * extended solely by a consumer outside the scan is reported as if not extended.
 */
interface ExtendedClassAwareRuleInterface extends RuleInterface
{
}
