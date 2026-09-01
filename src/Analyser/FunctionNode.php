<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * A named function declaration (`function foo() {}`), at the top level of a
 * file or a namespace. Closures and arrow functions are
 * {@see AnonymousFunctionNode}s instead.
 */
final readonly class FunctionNode
{
    use NodeQueryTrait;

    /** @var list<string> */
    public array $layers;

    /**
     * @param string       $functionName       Fully-qualified function name
     * @param list<string> $dependencies       Fully-qualified class, function, or constant dependencies
     * @param string[]     $functionCalls      Functions called within this function
     * @param string[]     $superglobals       Superglobals accessed ($_GET, $_POST, etc.)
     * @param string[]     $languageConstructs Language constructs used (exit, die, etc.)
     * @param list<string> $layers             All layer names this function belongs to; defaults to [$layer]
     */
    public function __construct(
        public string $functionName,
        public string $file,
        public int $line,
        public ?string $layer,
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

    public function shortName(): string
    {
        $position = strrpos($this->functionName, '\\');

        return $position === false
            ? $this->functionName
            : substr($this->functionName, $position + 1);
    }

    public function nameEndsWith(string $suffix): bool
    {
        return str_ends_with($this->shortName(), $suffix);
    }

    public function nameStartsWith(string $prefix): bool
    {
        return str_starts_with($this->shortName(), $prefix);
    }

    public function nameMatches(string $pattern, bool $isFullName = false): bool
    {
        return (bool) preg_match($pattern, $isFullName ? $this->functionName : $this->shortName());
    }
}
