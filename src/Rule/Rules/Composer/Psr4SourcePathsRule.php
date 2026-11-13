<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\ComposerJsonRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Util\Path;

use function array_map;
use function implode;
use function in_array;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final readonly class Psr4SourcePathsRule implements ComposerJsonRuleInterface
{
    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths,
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        $composer = $this->psr4PathResolver->composerConfig($basePath);

        if ($composer === null) {
            return null;
        }

        if ($this->sourcePaths === null) {
            return null;
        }

        $autoloadPaths = $this->normalisePaths(
            $this->psr4PathResolver->paths($basePath),
            $basePath
        );
        $missingPaths  = [];

        foreach ($this->normalisePaths($this->sourcePaths, $basePath) as $sourcePath) {
            if (! in_array($sourcePath, $autoloadPaths, true)) {
                $missingPaths[] = $sourcePath;
            }
        }

        if ($missingPaths === []) {
            return null;
        }

        $composerFile = Path::normalise(rtrim($basePath, '/') . '/composer.json', canonicalise: true);
        return $this->violation(
            sprintf(
                'PSR-4 source path(s) [%s] must exist in composer.json autoload or autoload-dev',
                implode(', ', $missingPaths)
            ),
            $composerFile
        );
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalisePaths(array $paths, string $basePath): array
    {
        $normalisedBase = Path::normalise($basePath);
        $canonicalBase  = Path::normalise($basePath, canonicalise: true);

        return array_map(
            static function (string $path) use ($normalisedBase, $canonicalBase): string {
                $path = Path::normalise(trim($path));

                if ($normalisedBase !== '' && str_starts_with($path, $normalisedBase . '/')) {
                    return substr($path, strlen($normalisedBase) + 1);
                }

                // Out-of-project path (e.g. "../shared/src" or its absolute form): express
                // it relative to the base path so both spellings compare equal.
                return Path::relativeTo($path, $canonicalBase) ?: $path;
            },
            $paths
        );
    }

    private function violation(string $message, string $file): RuleViolation
    {
        return new RuleViolation(
            message:   $message,
            file:      $file,
            line:      1,
            className: '',
            layer:     'Source',
        );
    }
}
