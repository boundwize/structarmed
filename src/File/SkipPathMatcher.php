<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\File;

use Boundwize\StructArmed\Util\Path;

use function array_unique;
use function array_values;
use function fnmatch;
use function implode;
use function realpath;
use function sort;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function substr;

/**
 * Compiles skip paths once and decides whether a given path is skipped.
 *
 * A non-glob skip path is matched as every location it may refer to, all at once:
 *  - the canonical path on disk, if it exists (resolved from the current working
 *    directory, or absolute),
 *  - the path resolved against the base path (absolute paths are kept as-is).
 * An absolute path therefore matches only that absolute location; it is not
 * re-anchored under the base path.
 *
 * A glob pattern is matched against both the absolute path and the base-relative path.
 *
 * Instances are cached per (base path, skip paths) pair, ignoring skip path
 * order and duplicates, and memoise per-path
 * results, since the same decision is requested for the same file by many rules.
 */
final class SkipPathMatcher
{
    /** @var array<string, self> */
    private static array $instances = [];

    private readonly string $normalisedBasePathWithSlash;

    /**
     * Canonical skip path prefixes, each with a trailing slash so a single
     * str_starts_with() against "$path/" covers both exact and prefix matches.
     *
     * @var list<string>
     */
    private readonly array $pathPrefixesWithSlash;

    /** @var list<string> */
    private readonly array $patterns;

    private readonly bool $hasMatchers;

    /** @var array<string, bool> */
    private array $results = [];

    /**
     * @param list<string> $skipPaths
     */
    public static function compile(string $basePath, array $skipPaths): self
    {
        $skipPaths = array_unique($skipPaths);
        sort($skipPaths);

        $cacheKey = $basePath . "\0" . implode("\0", $skipPaths);

        return self::$instances[$cacheKey] ??= new self($basePath, $skipPaths);
    }

    /**
     * @param list<string> $skipPaths
     */
    private function __construct(string $basePath, array $skipPaths)
    {
        $normalisedBasePath                = Path::normalise($basePath, canonicalise: true);
        $this->normalisedBasePathWithSlash = $normalisedBasePath . '/';

        $pathPrefixes = [];
        $patterns     = [];

        foreach ($skipPaths as $skipPath) {
            $normalisedSkipPath = Path::normalise($skipPath);

            if (strpbrk($skipPath, '*?[') !== false) {
                $patterns[] = $normalisedSkipPath;

                continue;
            }

            $canonicalSkipPath = realpath($skipPath);
            if ($canonicalSkipPath !== false) {
                $pathPrefixes[] = Path::normalise($canonicalSkipPath);
            }

            $pathPrefixes[] = Path::normalise(
                Path::resolve($normalisedSkipPath, $normalisedBasePath),
                canonicalise: true
            );
        }

        $pathPrefixesWithSlash = [];
        foreach (array_unique($pathPrefixes) as $pathPrefix) {
            $pathPrefixesWithSlash[] = $pathPrefix . '/';
        }

        $this->pathPrefixesWithSlash = $pathPrefixesWithSlash;
        $this->patterns              = array_values(array_unique($patterns));
        $this->hasMatchers           = $pathPrefixesWithSlash !== [] || $this->patterns !== [];
    }

    public function isSkipped(string $path): bool
    {
        if (! $this->hasMatchers) {
            return false;
        }

        return $this->results[$path] ??= $this->computeIsSkipped($path);
    }

    private function computeIsSkipped(string $path): bool
    {
        $normalisedPath = Path::normalise($path, canonicalise: true);
        $pathWithSlash  = $normalisedPath . '/';

        foreach ($this->pathPrefixesWithSlash as $pathPrefixWithSlash) {
            if (str_starts_with($pathWithSlash, $pathPrefixWithSlash)) {
                return true;
            }
        }

        if ($this->patterns === []) {
            return false;
        }

        $relativePath = str_starts_with($normalisedPath, $this->normalisedBasePathWithSlash)
            ? substr($normalisedPath, strlen($this->normalisedBasePathWithSlash))
            : $normalisedPath;

        foreach ($this->patterns as $pattern) {
            if (fnmatch($pattern, $normalisedPath) || fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
