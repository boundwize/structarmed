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
use function trim;

final class Psr4SourcePathsRule implements ProjectRuleInterface
{
    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private readonly ?array $sourcePaths,
        private readonly Psr4PathResolver $pathResolver = new Psr4PathResolver(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function sourcePathsFor(string $basePath): array
    {
        if ($this->sourcePaths === null) {
            return $this->pathResolver->paths($basePath);
        }

        return $this->normalisePaths($this->sourcePaths);
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

        $composer = $this->pathResolver->composerConfig($basePath);

        if ($composer === null) {
            return $this->violation(
                'composer.json is not valid JSON',
                $composerFile
            );
        }

        if ($this->sourcePaths === null) {
            return null;
        }

        $autoloadPaths = $this->pathResolver->paths($basePath);
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
