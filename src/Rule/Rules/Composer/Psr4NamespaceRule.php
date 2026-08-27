<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Util\Path;

use function array_key_exists;
use function array_key_first;
use function array_unique;
use function arsort;
use function dirname;
use function file_exists;
use function ltrim;
use function max;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class Psr4NamespaceRule implements RuleInterface, ProjectRuleInterface
{
    private ?string $projectBasePath = null;

    /** @var array<string, array<string, list<string>>> */
    private array $mappingsByBasePath = [];

    /** @var array<string, string|null> */
    private array $basePathByDirectory = [];

    public function __construct(
        private readonly string $layer,
        private readonly Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    /**
     * Records the analysed project root so PSR-4 paths pointing outside it
     * (e.g. "../shared/src/") are still checked; project rules run before class rules.
     */
    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        $this->projectBasePath = Path::normalise($basePath, canonicalise: true);

        return null;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $expectedClassNames = $this->expectedClassNames($classNode->file);

        if ($expectedClassNames === [] || isset($expectedClassNames[$classNode->className])) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                '%s [%s] must match PSR-4 class [%s]',
                $classNode->getType(),
                $classNode->className,
                array_key_first($expectedClassNames)
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }

    /**
     * @return array<string, int>
     */
    private function expectedClassNames(string $file): array
    {
        $basePaths = array_unique([$this->projectBasePath, $this->basePathFor($file)]);

        $file = Path::normalise($file, canonicalise: true);

        $candidates = [];

        foreach ($basePaths as $basePath) {
            if ($basePath === null) {
                continue;
            }

            foreach ($this->mappingsFor($basePath) as $namespace => $paths) {
                foreach ($paths as $path) {
                    $prefix = Path::normalise(Path::resolve($path, $basePath), canonicalise: true);

                    if (! str_starts_with($file, $prefix . '/')) {
                        continue;
                    }

                    $relativeClass = substr($file, strlen($prefix) + 1);

                    if (! str_ends_with($relativeClass, '.php')) {
                        continue;
                    }

                    $relativeClass = substr($relativeClass, 0, -4);
                    $relativeClass = (string) preg_replace('/\.class$/i', '', $relativeClass);
                    $relativeClass = str_replace('/', '\\', $relativeClass);

                    $className = $namespace . ltrim($relativeClass, '\\');

                    $candidates[$className] = max($candidates[$className] ?? 0, strlen($prefix));
                }
            }
        }

        arsort($candidates);

        return $candidates;
    }

    private function basePathFor(string $file): ?string
    {
        $directory = dirname(Path::normalise($file, canonicalise: true));

        if (array_key_exists($directory, $this->basePathByDirectory)) {
            return $this->basePathByDirectory[$directory];
        }

        $visited  = [];
        $basePath = null;

        while ($directory !== '' && $directory !== '.') {
            if (array_key_exists($directory, $this->basePathByDirectory)) {
                $basePath = $this->basePathByDirectory[$directory];
                break;
            }

            $visited[] = $directory;

            if (file_exists($directory . '/composer.json')) {
                $basePath = $directory;
                break;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        foreach ($visited as $visitedDirectory) {
            $this->basePathByDirectory[$visitedDirectory] = $basePath;
        }

        return $basePath;
    }

    /**
     * @return array<string, list<string>>
     */
    private function mappingsFor(string $basePath): array
    {
        $this->mappingsByBasePath[$basePath] ??= $this->psr4PathResolver->namespacePaths($basePath);

        return $this->mappingsByBasePath[$basePath];
    }
}
