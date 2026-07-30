<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\File;

use Boundwize\StructArmed\Util\Path;

use function array_unique;
use function array_values;
use function fnmatch;
use function implode;
use function ltrim;
use function realpath;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function substr;

/**
 * Compiles skip paths once and decides whether a given path is skipped.
 *
 * A skip path may be:
 *  - a path that exists relative to the current working directory (or absolute),
 *  - a path relative to the base path (a leading slash is treated as base-relative),
 *  - a glob pattern, matched against both the absolute path and the base-relative path.
 *
 * Instances are cached per (base path, skip paths) pair and memoise per-path
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

            $baseRelativePath = ltrim($normalisedSkipPath, '/');
            $pathPrefixes[]   = Path::normalise(
                $baseRelativePath === ''
                    ? $normalisedBasePath
                    : $normalisedBasePath . '/' . $baseRelativePath,
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
