<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function array_map;
use function file_exists;
use function implode;
use function in_array;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final readonly class Psr4SourcePathsRule implements ProjectRuleInterface
{
    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths,
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function sourcePathsFor(string $basePath): array
    {
        if ($this->sourcePaths === null) {
            return $this->psr4PathResolver->paths($basePath);
        }

        return $this->normalisePaths($this->sourcePaths, $basePath);
    }

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

        if (! file_exists($composerFile)) {
            return $this->violation(
                'composer.json was not found',
                $composerFile
            );
        }

        $composer = $this->psr4PathResolver->composerConfig($basePath);

        if ($composer === null) {
            return $this->violation(
                'composer.json is not valid JSON',
                $composerFile
            );
        }

        if ($this->sourcePaths === null) {
            return null;
        }

        $autoloadPaths = $this->psr4PathResolver->paths($basePath);
        $missingPaths  = [];

        foreach ($this->normalisePaths($this->sourcePaths, $basePath) as $sourcePath) {
            if (! in_array($sourcePath, $autoloadPaths, true)) {
                $missingPaths[] = $sourcePath;
            }
        }

        if ($missingPaths === []) {
            return null;
        }

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
    private function normalisePaths(array $paths, string $basePath = ''): array
    {
        $normalisedBase = rtrim(str_replace('\\', '/', $basePath), '/');

        return array_map(
            static function (string $path) use ($normalisedBase): string {
                $path = rtrim(str_replace('\\', '/', trim($path)), '/');

                if ($normalisedBase !== '' && str_starts_with($path, $normalisedBase . '/')) {
                    return substr($path, strlen($normalisedBase) + 1);
                }

                return $path;
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
