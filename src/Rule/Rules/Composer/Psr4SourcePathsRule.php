<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function array_map;
use function file_exists;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function sprintf;
use function str_replace;
use function trim;

final class Psr4SourcePathsRule implements ProjectRuleInterface
{
    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private readonly array $sourcePaths,
    ) {
    }

    public function evaluateProject(string $basePath, Architecture $architecture): ?RuleViolation
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

        if (! file_exists($composerFile)) {
            return $this->violation(
                'composer.json was not found',
                $composerFile
            );
        }

        $composer = json_decode((string) file_get_contents($composerFile), true);

        if (! is_array($composer)) {
            return $this->violation(
                'composer.json is not valid JSON',
                $composerFile
            );
        }

        /** @var array<string, mixed> $composer */
        $autoloadPaths = $this->psr4Paths($composer);
        $missingPaths  = [];

        foreach ($this->normalisePaths($this->sourcePaths) as $sourcePath) {
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
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    private function psr4Paths(array $composer): array
    {
        $paths = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];

            if (! is_array($autoload)) {
                continue;
            }

            $psr4 = $autoload['psr-4'] ?? [];

            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $pathConfig) {
                foreach ((array) $pathConfig as $path) {
                    if (is_string($path)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return $this->normalisePaths($paths);
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

    private function violation(string $message, string $file): RuleViolation
    {
        return new RuleViolation(
            ruleKey:   '',
            message:   $message,
            file:      $file,
            line:      1,
            className: '',
            layer:     'Source',
        );
    }
}
