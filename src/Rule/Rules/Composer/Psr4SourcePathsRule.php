<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function array_map;
use function array_unique;
use function array_values;
use function file_exists;
use function implode;
use function in_array;
use function is_dir;
use function ltrim;
use function rtrim;
use function sprintf;
use function str_replace;
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

        return $this->normalisePaths($this->sourcePaths);
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

        $autoloadPaths = $this->psr4PathResolver->paths($basePath);
        $messages      = [];

        if ($this->sourcePaths !== null) {
            $missingPaths = [];

            foreach ($this->normalisePaths($this->sourcePaths) as $sourcePath) {
                if (! in_array($sourcePath, $autoloadPaths, true)) {
                    $missingPaths[] = $sourcePath;
                }
            }

            if ($missingPaths !== []) {
                $messages[] = sprintf(
                    'PSR-4 source path(s) [%s] must exist in composer.json autoload or autoload-dev',
                    implode(', ', $missingPaths)
                );
            }
        }

        $missingDirectories = $this->missingComposerDirectories($basePath);

        if ($missingDirectories !== []) {
            $messages[] = sprintf(
                'PSR-4 directory path(s) [%s] configured in composer.json autoload or autoload-dev must exist',
                implode(', ', $missingDirectories)
            );
        }

        if ($messages === []) {
            return null;
        }

        return $this->violation(
            implode('; ', $messages),
            $composerFile
        );
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalisePaths(array $paths): array
    {
        return array_map(
            static fn(string $path): string => rtrim(str_replace('\\', '/', trim($path)), '/'),
            $paths
        );
    }

    /**
     * @return list<string>
     */
    private function missingComposerDirectories(string $basePath): array
    {
        $missingDirectories = [];

        foreach ($this->psr4PathResolver->namespacePaths($basePath) as $namespace => $paths) {
            foreach ($paths as $path) {
                if (is_dir(rtrim($basePath, '/') . '/' . ltrim($path, '/'))) {
                    continue;
                }

                $missingDirectories[] = $namespace === '' ? $path : $namespace . ' => ' . $path;
            }
        }

        return array_values(array_unique($missingDirectories));
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
