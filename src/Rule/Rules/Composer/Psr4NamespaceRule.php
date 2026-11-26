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
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class Psr4NamespaceRule implements RuleInterface, ProjectRuleInterface
{
    private const IDENTIFIER = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';

    private const CLASS_NAME_PATTERN = '/^' . self::IDENTIFIER . '(?:\\\\' . self::IDENTIFIER . ')*$/D';

    private ?string $projectBasePath = null;

    /** @var array<string, array<string, list<string>>> Base path => namespace => canonical path prefixes */
    private array $mappingsByBasePath = [];

    /** @var array<string, string|null> */
    private array $basePathByDirectory = [];

    /** @var array<string, list<array{string, int}>> Directory => namespace and length of each matching path prefix */
    private array $namespacePrefixesByDirectory = [];

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
        $this->projectBasePath              = Path::normalise($basePath, canonicalise: true);
        $this->namespacePrefixesByDirectory = [];

        return null;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $expectedClassNames = $this->expectedClassNames($classNode->file);

        if ($expectedClassNames === [] || isset($expectedClassNames[$classNode->className])) {
            return null;
        }

        $expectedClassName = array_key_first($expectedClassNames);
        $format            = preg_match(self::CLASS_NAME_PATTERN, $expectedClassName) === 1
            ? '%s [%s] must match PSR-4 class [%s]'
            : '%s [%s] is in a file that maps to invalid PSR-4 class name [%s]';

        return new RuleViolation(
            message:   sprintf($format, $classNode->getType(), $classNode->className, $expectedClassName),
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

        $candidates = [];

        foreach ($this->namespacePrefixesFor(dirname($file)) as [$namespace, $prefixLength]) {
            $relativeClass = str_replace('/', '\\', substr($file, $prefixLength + 1, -4));
            $className     = $namespace . ltrim($relativeClass, '\\');

            $candidates[$className] = max($candidates[$className] ?? 0, $prefixLength);
        }

        arsort($candidates);

        return $candidates;
    }

    /**
     * The files of one directory sit under the same PSR-4 paths, so those are
     * matched once per directory, not once per class.
     *
     * @return list<array{string, int}> Namespace and length of each matching path prefix
     */
    private function namespacePrefixesFor(string $directory): array
    {
        if (isset($this->namespacePrefixesByDirectory[$directory])) {
            return $this->namespacePrefixesByDirectory[$directory];
        }

        $namespacePrefixes = [];

        foreach (array_unique([$this->projectBasePath, $this->basePathFor($directory)]) as $basePath) {
            if ($basePath === null) {
                continue;
            }

            foreach ($this->mappingsFor($basePath) as $namespace => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($directory . '/', $prefix . '/')) {
                        $namespacePrefixes[] = [$namespace, strlen($prefix)];
                    }
                }
            }
        }

        return $this->namespacePrefixesByDirectory[$directory] = $namespacePrefixes;
    }

    private function basePathFor(string $directory): ?string
    {
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
     * PSR-4 paths are resolved once per base path, not once per class.
     *
     * @return array<string, list<string>> Namespace => canonical path prefixes
     */
    private function mappingsFor(string $basePath): array
    {
        if (isset($this->mappingsByBasePath[$basePath])) {
            return $this->mappingsByBasePath[$basePath];
        }

        $mappings = [];

        foreach ($this->psr4PathResolver->namespacePaths($basePath) as $namespace => $paths) {
            foreach ($paths as $path) {
                $mappings[$namespace][] = Path::normalise(Path::resolve($path, $basePath), canonicalise: true);
            }
        }

        return $this->mappingsByBasePath[$basePath] = $mappings;
    }
}
