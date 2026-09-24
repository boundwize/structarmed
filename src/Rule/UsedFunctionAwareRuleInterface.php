<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

/**
 * Marker for rules whose evaluation depends on whether a named function is
 * used within the scanned paths — called (including as a first-class
 * callable) or referenced by a function-name string such as a callable
 * 'App\helper'. When at least one active rule implements this marker, the
 * analyser flags each FunctionNode's $isReferenced before rules are
 * evaluated, so implementers can read $functionNode->isReferenced.
 *
 * Trade-off: only usage within the scanned paths is known. A function used
 * solely by a consumer outside the scan is reported as if not used.
 */
interface UsedFunctionAwareRuleInterface extends FunctionRuleInterface
{
}
