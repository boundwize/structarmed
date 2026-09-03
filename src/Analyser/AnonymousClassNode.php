<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function in_array;

/**
 * An anonymous class declaration (`new class ... {}`). Anonymous classes never
 * become ClassNodes — they cannot be referenced by name — so one is identified
 * by its file and line, plus the named class-like and/or function it is
 * declared in, and rules target it through
 * {@see \Boundwize\StructArmed\Rule\AnonymousClassRuleInterface}.
 *
 * The class it extends, the interfaces it implements, and the traits it uses
 * are still used within the scanned paths, which usage-aware rules must take
 * into account: MustBeFinalRule must skip a class extended by an anonymous class.
 */
final readonly class AnonymousClassNode
{
    /**
     * Scope label reported by {@see enclosingScopeName()} for an anonymous
     * class declared outside any class-like or named function.
     */
    public const FILE_SCOPE = 'file scope';

    /** @var list<string> */
    public array $layers;

    /**
     * @param string[]     $implements            Interface names this anonymous class implements
     * @param string[]     $traits                Trait names this anonymous class uses
     * @param string|null  $enclosingClassName    Innermost named class-like this anonymous class is declared in
     * @param string|null  $enclosingFunctionName Innermost named function this anonymous class is declared in
     * @param bool         $hasEmptyParentheses   Whether `()` follows `class` although no constructor argument
     *                                            is passed: `new class () {}` rather than `new class {}`
     * @param list<string> $layers                Layer names this anonymous class belongs to; defaults to [$layer]
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $extends,
        public array $implements = [],
        public array $traits = [],
        public ?string $layer = null,
        public ?string $enclosingClassName = null,
        public ?string $enclosingFunctionName = null,
        public bool $hasEmptyParentheses = false,
        array $layers = [],
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    public function isInLayer(string $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }

    /**
     * Label of the innermost named scope declaring this anonymous class —
     * the enclosing class-like, else the enclosing named function — or
     * {@see self::FILE_SCOPE} for one declared in top-level procedural code.
     */
    public function enclosingScopeName(): string
    {
        return $this->enclosingClassName ?? $this->enclosingFunctionName ?? self::FILE_SCOPE;
    }
}
