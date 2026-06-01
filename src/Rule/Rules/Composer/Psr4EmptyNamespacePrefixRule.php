<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function array_keys;
use function file_exists;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;
use function trim;

final readonly class Psr4EmptyNamespacePrefixRule implements MultipleProjectRuleViolationInterface
{
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
     * @param string[] $skipPaths
     */
    public function evaluateProjectAll(string $basePath, Architecture $architecture, array $skipPaths = []): array
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

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

            foreach (array_keys($psr4) as $namespace) {
                if (! is_string($namespace)) {
                    continue;
                }

                if (trim($namespace, '\\') !== '') {
                    continue;
                }

                $violations[] = new RuleViolation(
                    message:   sprintf(
                        'PSR-4 entry ["%s"] in %s has an empty namespace prefix;'
                            . ' declare a specific namespace prefix such as "App\\\\"',
                        $namespace,
                        $section
                    ),
                    file:      $composerFile,
                    line:      1,
                    className: '',
                    layer:     'Source',
                );
            }
        }

        return $violations;
    }
}
