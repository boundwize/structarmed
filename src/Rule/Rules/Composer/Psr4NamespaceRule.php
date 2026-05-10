<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Composer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function dirname;
use function file_exists;
use function ltrim;
use function preg_replace;
use function realpath;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class Psr4NamespaceRule implements RuleInterface
{
    /** @var array<string, array<string, list<string>>> */
    private array $mappingsByBasePath = [];

    public function __construct(
        private readonly string $layer,
        private readonly Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $expectedClassName = $this->expectedClassName($classNode->file);

        if ($expectedClassName === null || $classNode->className === $expectedClassName) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must match PSR-4 class [%s]',
                $classNode->className,
                $expectedClassName
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }

    private function expectedClassName(string $file): ?string
    {
        $basePath = $this->basePathFor($file);

        if ($basePath === null) {
            return null;
        }

        $file = $this->normalisePath($file);

        foreach ($this->mappingsFor($basePath) as $namespace => $paths) {
            foreach ($paths as $path) {
                $prefix = $this->normalisePath($basePath . '/' . $path);

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

                return $namespace . ltrim($relativeClass, '\\');
            }
        }

        return null;
    }

    private function basePathFor(string $file): ?string
    {
        $directory = dirname($this->normalisePath($file));

        while ($directory !== '' && $directory !== '.') {
            if (file_exists($directory . '/composer.json')) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function mappingsFor(string $basePath): array
    {
        if (! isset($this->mappingsByBasePath[$basePath])) {
            $this->mappingsByBasePath[$basePath] = $this->psr4PathResolver->namespacePaths($basePath);
        }

        return $this->mappingsByBasePath[$basePath];
    }

    private function normalisePath(string $path): string
    {
        $path = realpath($path) ?: $path;
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('#/+#', '/', $path);

        return rtrim($path, '/');
    }
}
