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
use function basename;
use function dirname;
use function file_exists;
use function ltrim;
use function max;
use function preg_replace;
use function rtrim;
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

    /** @var array<string, array<string, int>> */
    private array $namespaceCandidatesByDirectory = [];

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
        $this->projectBasePath                = Path::normalise($basePath, canonicalise: true);
        $this->namespaceCandidatesByDirectory = [];

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
        $file = Path::normalise($file, canonicalise: true);

        if (! str_ends_with($file, '.php')) {
            return [];
        }

        $directory = dirname($file);
        $shortName = basename($file, '.php');
        $shortName = (string) preg_replace('/\.class$/i', '', $shortName);

        $candidates = [];

        foreach ($this->namespaceCandidatesFor($directory, $file) as $namespace => $prefixLength) {
            $candidates[$namespace . $shortName] = $prefixLength;
        }

        return $candidates;
    }

    /** @return array<string, int> */
    private function namespaceCandidatesFor(string $directory, string $file): array
    {
        if (isset($this->namespaceCandidatesByDirectory[$directory])) {
            return $this->namespaceCandidatesByDirectory[$directory];
        }

        $basePaths          = array_unique([$this->projectBasePath, $this->basePathFor($file)]);
        $directoryWithSlash = rtrim($directory, '/') . '/';
        $candidates         = [];

        foreach ($basePaths as $basePath) {
            if ($basePath === null) {
                continue;
            }

            foreach ($this->mappingsFor($basePath) as $namespace => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (! str_starts_with($directoryWithSlash, $prefix . '/')) {
                        continue;
                    }

                    $relativeNamespace = substr($directoryWithSlash, strlen($prefix) + 1);
                    $relativeNamespace = str_replace('/', '\\', $relativeNamespace);
                    $candidate         = $namespace . ltrim($relativeNamespace, '\\');

                    $candidates[$candidate] = max($candidates[$candidate] ?? 0, strlen($prefix));
                }
            }
        }

        arsort($candidates);

        return $this->namespaceCandidatesByDirectory[$directory] = $candidates;
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
        if (isset($this->mappingsByBasePath[$basePath])) {
            return $this->mappingsByBasePath[$basePath];
        }

        $mappings = [];

        // Resolve directory prefixes once per composer root, instead of for every class.
        foreach ($this->psr4PathResolver->namespacePaths($basePath) as $namespace => $paths) {
            foreach ($paths as $path) {
                $mappings[$namespace][] = Path::normalise(Path::resolve($path, $basePath), canonicalise: true);
            }
        }

        return $this->mappingsByBasePath[$basePath] = $mappings;
    }
}
