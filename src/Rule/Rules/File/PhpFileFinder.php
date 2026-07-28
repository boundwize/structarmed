<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Util\Path;
use Boundwize\StructArmed\Util\PhpFileWalker;

use function array_unique;
use function array_values;
use function fnmatch;
use function is_dir;
use function ltrim;
use function realpath;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function substr;

final readonly class PhpFileFinder
{
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    /**
     * @param list<string> $skipPaths
     * @return list<string>
     */
    public function files(string $basePath, array $skipPaths = []): array
    {
        $normalisedBase = Path::normalise($basePath, canonicalise: true);
        $skipMatchers   = $this->compileSkipMatchers($normalisedBase, $skipPaths);
        $isSkipped      = fn(string $file): bool => $this->isSkipped($file, $normalisedBase, $skipMatchers);

        $files = [];

        $sourcePaths = array_unique($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath));
        foreach ($sourcePaths as $sourcePath) {
            $fullPath = Path::resolve($sourcePath, $basePath);

            if (! is_dir($fullPath)) {
                continue;
            }

            foreach (PhpFileWalker::files($fullPath, $isSkipped) as $file) {
                $files[] = $file;
            }
        }

        // ensure nothing duplicated once more
        // avoid inner directory provided by multiple source paths
        return array_values(array_unique($files));
    }

    /**
     * @param list<string> $skipPaths
     * @return list<array{absolutePath: string|null, baseRelativePath: string, pattern: string|null}>
     */
    private function compileSkipMatchers(string $normalisedBase, array $skipPaths): array
    {
        $skipMatchers = [];

        foreach ($skipPaths as $skipPath) {
            $baseRelativePath = Path::normalise(ltrim($skipPath, '/'));
            $fullSkipPath     = $baseRelativePath === '' ? $normalisedBase : $normalisedBase . '/' . $baseRelativePath;

            $skipMatchers[] = [
                'absolutePath'     => realpath($skipPath) !== false
                    ? Path::normalise($skipPath, canonicalise: true)
                    : null,
                'baseRelativePath' => Path::normalise($fullSkipPath, canonicalise: true),
                'pattern'          => strpbrk($skipPath, '*?[') !== false
                    ? Path::normalise($skipPath)
                    : null,
            ];
        }

        return $skipMatchers;
    }

    /**
     * @param list<array{absolutePath: string|null, baseRelativePath: string, pattern: string|null}> $skipMatchers
     */
    private function isSkipped(string $filePath, string $normalisedBase, array $skipMatchers): bool
    {
        if ($skipMatchers === []) {
            return false;
        }

        $normalisedFile = Path::normalise($filePath, canonicalise: true);
        $relativePath   = substr($normalisedFile, strlen($normalisedBase) + 1);

        foreach ($skipMatchers as $skipMatcher) {
            if (
                $skipMatcher['absolutePath'] !== null
                && (
                    $normalisedFile === $skipMatcher['absolutePath']
                    || str_starts_with($normalisedFile, $skipMatcher['absolutePath'] . '/')
                )
            ) {
                return true;
            }

            if (
                $normalisedFile === $skipMatcher['baseRelativePath']
                || str_starts_with($normalisedFile, $skipMatcher['baseRelativePath'] . '/')
            ) {
                return true;
            }

            if (
                $skipMatcher['pattern'] !== null
                && (
                    fnmatch($skipMatcher['pattern'], $normalisedFile)
                    || fnmatch($skipMatcher['pattern'], $relativePath)
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
