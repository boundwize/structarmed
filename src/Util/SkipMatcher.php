<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function fnmatch;
use function implode;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_replace;
use function strpbrk;

/**
 * Compiled skip-path matcher.
 *
 * Literal paths and simple globs are merged into single regexes so a path is
 * checked with at most two preg_match() calls instead of one fnmatch() per
 * pattern. Verdicts are memoised per path because the analyser checks the
 * same file once per rule. Compiled instances are shared for identical skip
 * path lists, so rules without rule-specific skips reuse one verdict cache.
 */
final class SkipMatcher
{
    /** @var array<string, self> */
    private static array $compiled = [];

    /** @var array<string, bool> */
    private array $verdicts = [];

    private readonly bool $empty;

    /**
     * @param list<string> $fnmatchPatterns Patterns with character classes, kept on the fnmatch() path.
     */
    private function __construct(
        private readonly ?string $prefixRegex,
        private readonly ?string $globRegex,
        private readonly array $fnmatchPatterns,
    ) {
        $this->empty = $prefixRegex === null && $globRegex === null && $fnmatchPatterns === [];
    }

    /**
     * @param list<string> $skipPaths
     */
    public static function compile(array $skipPaths, string $normalisedBasePath): self
    {
        $cacheKey = $normalisedBasePath . "\0" . implode("\0", $skipPaths);

        if (isset(self::$compiled[$cacheKey])) {
            return self::$compiled[$cacheKey];
        }

        $prefixParts     = [];
        $globParts       = [];
        $fnmatchPatterns = [];

        foreach ($skipPaths as $skipPath) {
            $absoluteSkipPath = Path::resolve(Path::normalise($skipPath), $normalisedBasePath);

            if (strpbrk($absoluteSkipPath, '*?[') === false) {
                $prefixParts[] = preg_quote(Path::normalise($absoluteSkipPath, canonicalise: true), '#');

                continue;
            }

            if (! str_contains($absoluteSkipPath, '[')) {
                // fnmatch() without FNM_PATHNAME lets * and ? cross "/", hence .* and .
                $globParts[] = str_replace(['\*', '\?'], ['.*', '.'], preg_quote($absoluteSkipPath, '#'));

                continue;
            }

            $fnmatchPatterns[] = $absoluteSkipPath;
        }

        return self::$compiled[$cacheKey] = new self(
            $prefixParts === [] ? null : '#^(?:' . implode('|', $prefixParts) . ')(?:/|$)#',
            $globParts === [] ? null : '#^(?:' . implode('|', $globParts) . ')$#s',
            $fnmatchPatterns,
        );
    }

    public function isSkipped(string $path): bool
    {
        if ($this->empty) {
            return false;
        }

        return $this->verdicts[$path] ??= $this->matches($path);
    }

    private function matches(string $path): bool
    {
        $normalisedPath = Path::normalise($path, canonicalise: true);

        if ($this->prefixRegex !== null && preg_match($this->prefixRegex, $normalisedPath) === 1) {
            return true;
        }

        if ($this->globRegex !== null && preg_match($this->globRegex, $normalisedPath) === 1) {
            return true;
        }

        foreach ($this->fnmatchPatterns as $fnmatchPattern) {
            if (fnmatch($fnmatchPattern, $normalisedPath)) {
                return true;
            }
        }

        return false;
    }
}
