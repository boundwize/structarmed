<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Capture\CollectorState;

/**
 * References and instantiations collected in one file, including the function
 * declarations and call-resolution state needed to distinguish direct calls
 * from namespace fallback markers.
 *
 * @internal
 */
final class FileReferenceAnalysis
{
    /** @var array<string, true> */
    public array $references = [];

    /** @var array<string, true> */
    public array $instantiations = [];

    /** @var array<string, true> Lower-cased short names of fallback markers */
    public array $fallbackNames = [];

    /** @var array<string, string> Resolved global calls, keyed by lower-cased name */
    public array $globalFunctionCalls = [];

    /** @var array<string, string> Resolved unqualified calls, keyed by namespaced spelling */
    public array $resolvedFunctionNames = [];

    /**
     * Lower-cased names of functions declared unconditionally at namespace
     * level. Conditional or nested declarations cannot resolve an unqualified
     * call: PHP may still fall back to the global function.
     *
     * Retained after traversal for the extractor, then reset for the next file.
     *
     * @var array<string, true>
     */
    public array $declaredFunctions = [];
}
