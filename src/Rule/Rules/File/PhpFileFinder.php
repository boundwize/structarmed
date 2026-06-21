<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use AppendIterator;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Util\Path;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_unique;
use function array_values;
use function fnmatch;
use function is_dir;
use function ltrim;
use function realpath;
use function str_starts_with;
use function strlen;
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
        $append         = new AppendIterator();

        $sourcePaths = array_unique($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath));
        foreach ($sourcePaths as $sourcePath) {
            $fullPath = Path::resolve($sourcePath, $basePath);

            if (! is_dir($fullPath)) {
                continue;
            }

            $append->append(new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS),
                    function (SplFileInfo $file) use ($normalisedBase, $skipMatchers): bool {
                        $isRealDirectory = $file->isDir() && ! $file->isLink();
                        if (! $isRealDirectory && $file->getExtension() !== 'php') {
                            return false;
                        }

                        return ! $this->isSkipped($file->getPathname(), $normalisedBase, $skipMatchers);
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            ));
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($append as $file) {
            $files[] = $file->getPathname();
        }

        // ensure nothing duplicated once more
        // avoid inner directory provided by multiple source paths
        return array_values(array_unique($files));
    }

    /**
     * @param list<string> $skipPaths
     * @return list<array{absolutePath: string|null, baseRelativePath: string, pattern: string}>
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
                'pattern'          => Path::normalise($skipPath),
            ];
        }

        return $skipMatchers;
    }

    /**
     * @param list<array{absolutePath: string|null, baseRelativePath: string, pattern: string}> $skipMatchers
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
                fnmatch($skipMatcher['pattern'], $normalisedFile)
                || fnmatch($skipMatcher['pattern'], $relativePath)
            ) {
                return true;
            }
        }

        return false;
    }
}
