<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function file_exists;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;
use function trim;

final readonly class Psr4RootPathRule implements MultipleProjectRuleViolationInterface
{
    use SkipsComposerFileTrait;

    public function __construct(
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        return $this->evaluateProjectAll($basePath, $architecture, $skipPaths)[0] ?? null;
    }

    /**
     * @return list<RuleViolation>
     * @param list<string> $skipPaths
     */
    public function evaluateProjectAll(string $basePath, Architecture $architecture, array $skipPaths = []): array
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

        if ($this->isComposerFileSkipped($basePath, $composerFile, $skipPaths)) {
            return [];
        }

        if (! file_exists($composerFile)) {
            return [];
        }

        $composer = $this->psr4PathResolver->composerConfig($basePath);

        if ($composer === null) {
            return [];
        }

        $violations = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];

            if (! is_array($autoload)) {
                continue;
            }

            $psr4 = $autoload['psr-4'] ?? [];

            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $namespace => $pathConfig) {
                if (! is_string($namespace)) {
                    continue;
                }

                foreach ((array) $pathConfig as $path) {
                    if (! is_string($path)) {
                        continue;
                    }

                    $trimmedPath = trim($path);

                    if ($trimmedPath !== '' && rtrim($trimmedPath, '/\\') !== '.') {
                        continue;
                    }

                    $violations[] = new RuleViolation(
                        message:   sprintf(
                            'PSR-4 entry ["%s"] => ["%s"] in %s maps to the project root;'
                                . ' declare a specific directory instead',
                            $namespace,
                            $path,
                            $section
                        ),
                        file:      $composerFile,
                        line:      1,
                        className: '',
                        layer:     'Source',
                    );
                }
            }
        }

        return $violations;
    }
}
