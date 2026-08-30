<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;

/**
 * An anonymous function: a closure (`function () {}`) or an arrow function
 * (`fn () => ...`). It has no name of its own, so it is identified by its file
 * and line, plus the named class-like and/or function it is declared in.
 *
 * The body-level facts of an anonymous function declared inside a class-like
 * or named function are also counted on that enclosing node, exactly as the
 * body of a method is counted on its class: a rule that only inspects the
 * enclosing node keeps seeing everything the closure does.
 */
final readonly class AnonymousFunctionNode
{
    use FunctionLikeNodeTrait;

    /**
     * Scope label reported by {@see enclosingScopeName()} for an anonymous
     * function declared outside any class-like or named function.
     */
    public const FILE_SCOPE = 'file scope';

    /** @var list<string> */
    public array $layers;

    /**
     * @param string|null  $enclosingClassName    Innermost named class-like this anonymous function is declared in
     * @param string|null  $enclosingFunctionName Innermost named function this anonymous function is declared in
     * @param bool         $usesThis              Whether the body (or a nested closure) reads `$this`; such a
     *                                            closure cannot be declared static
     * @param list<string> $dependencies          Fully-qualified class, function, or constant dependencies
     * @param string[]     $functionCalls         Functions called within this anonymous function
     * @param string[]     $superglobals          Superglobals accessed ($_GET, $_POST, etc.)
     * @param string[]     $languageConstructs    Language constructs used (exit, die, etc.)
     * @param list<string> $layers                Layer names this anonymous function belongs to; defaults to [$layer]
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $layer,
        public bool $isArrowFunction = false,
        public bool $isStatic = false,
        public ?string $enclosingClassName = null,
        public ?string $enclosingFunctionName = null,
        public bool $usesThis = false,
        public bool $hasReturnType = false,
        public int $paramCount = 0,
        public int $cyclomaticComplexity = 1,
        public int $lineCount = 0,
        public array $dependencies = [],
        public array $functionCalls = [],
        public array $superglobals = [],
        public array $languageConstructs = [],
        array $layers = [],
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    public function getType(): string
    {
        return $this->isArrowFunction ? 'Arrow function' : 'Closure';
    }

    /**
     * Label of the innermost named scope declaring this anonymous function —
     * the enclosing class-like, else the enclosing named function — or
     * {@see self::FILE_SCOPE} for one declared in top-level procedural code.
     */
    public function enclosingScopeName(): string
    {
        return $this->enclosingClassName ?? $this->enclosingFunctionName ?? self::FILE_SCOPE;
    }
}
