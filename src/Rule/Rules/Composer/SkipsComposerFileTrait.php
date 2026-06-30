<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Util\Path;

use function fnmatch;
use function str_starts_with;
use function strpbrk;

trait SkipsComposerFileTrait
{
    /**
     * @param list<string> $skipPaths
     */
    private function isComposerFileSkipped(string $basePath, string $composerFile, array $skipPaths): bool
    {
        if ($skipPaths === []) {
            return false;
        }

        $normalisedBasePath     = Path::normalise($basePath, canonicalise: true);
        $normalisedComposerFile = Path::normalise($composerFile, canonicalise: true);

        foreach ($skipPaths as $skipPath) {
            $absoluteSkipPath = Path::resolve(Path::normalise($skipPath), $normalisedBasePath);

            if (strpbrk($absoluteSkipPath, '*?[') !== false) {
                if (fnmatch($absoluteSkipPath, $normalisedComposerFile)) {
                    return true;
                }

                continue;
            }

            $normalisedSkipPath = Path::normalise($absoluteSkipPath, canonicalise: true);

            if (
                $normalisedComposerFile === $normalisedSkipPath
                || str_starts_with($normalisedComposerFile, $normalisedSkipPath . '/')
            ) {
                return true;
            }
        }

        return false;
    }
}
