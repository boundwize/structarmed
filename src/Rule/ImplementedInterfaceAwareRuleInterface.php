<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

/**
 * Marker for rules whose evaluation depends on whether an interface is
 * implemented by a scanned class (directly or through inheritance) or extended
 * by another scanned interface. When at least one active rule implements this
 * marker, the analyser flags each ClassNode's $isImplemented before rules are
 * evaluated, so implementers can read $classNode->isImplemented.
 *
 * Trade-off: only usage within the scanned paths is known. An interface
 * implemented solely by a consumer outside the scan is reported as if not
 * implemented.
 */
interface ImplementedInterfaceAwareRuleInterface extends RuleInterface
{
}
