<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Analyser
{
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
    }

    /**
     * @param list<string> $scanPaths
     */
    public function analyse(Architecture $architecture, array $scanPaths = []): RuleViolationCollection
    {
        $violations = new RuleViolationCollection();
        $layers     = $this->resolveLayers($architecture);
        $skipPaths  = $architecture->getSkipPaths();
        $ruleSkipPaths = $architecture->getRuleSkipPaths();

        foreach ($architecture->getProjectRules() as $key => $rule) {
            $violation = $rule->evaluateProject($this->basePath, $architecture);

            if ($violation === null) {
                continue;
            }

            $violations->add(new RuleViolation(
                ruleKey:   $key,
                message:   $violation->message,
                file:      $violation->file,
                line:      $violation->line,
                className: $violation->className,
                layer:     $violation->layer,
            ));
        }

        $resolver   = new ChainLayerResolver(
            new NamespaceLayerResolver($layers, $this->basePath)
        );

        $collector = new ClassCollector($resolver);
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($this->scanPaths($layers, $scanPaths) as $layerPath) {
            $fullPath = rtrim($this->basePath, '/') . '/' . ltrim($layerPath, '/');

            if (! is_dir($fullPath)) {
                continue;
            }

            if ($this->isSkipped($fullPath, $skipPaths)) {
                continue;
            }

            foreach ($this->phpFiles($fullPath) as $file) {
                if ($this->isSkipped($file, $skipPaths)) {
                    continue;
                }

                try {
                    $code = (string) file_get_contents($file);
                    $ast  = $parser->parse($code);

                    if ($ast === null || $ast === []) {
                        continue;
                    }

                    $collector->setCurrentFile($file);

                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new NameResolver());
                    $traverser->addVisitor($collector);
                    $traverser->traverse($ast);
                } catch (Error) {
                    // Skip files with parse errors
                }
            }
        }

        $rules = $architecture->getRules();

        foreach ($collector->getNodes() as $node) {
            foreach ($rules as $key => $rule) {
                if ($this->isSkipped($node->file, $ruleSkipPaths[$key] ?? [])) {
                    continue;
                }

                if (! $rule->appliesTo($node)) {
                    continue;
                }

                $violation = $rule->evaluate($node);

                if ($violation === null) {
                    continue;
                }

                // Inject the rule key into the violation
                $violations->add(new RuleViolation(
                    ruleKey:   $key,
                    message:   $violation->message,
                    file:      $violation->file,
                    line:      $violation->line,
                    className: $violation->className,
                    layer:     $violation->layer,
                ));
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function resolveLayers(Architecture $architecture): array
    {
        $layers = $architecture->getLayers();

        foreach ($architecture->getProjectRules() as $rule) {
            if (
                $rule instanceof Psr4SourcePathsRule
                && ($layers[Psr4Preset::SOURCE_LAYER] ?? null) === []
            ) {
                $layers[Psr4Preset::SOURCE_LAYER] = $rule->sourcePathsFor($this->basePath);
            }
        }

        return $layers;
    }

    /**
     * @param array<string, string|list<string>> $layers
     * @param list<string> $scanPaths
     * @return list<string>
     */
    private function scanPaths(array $layers, array $scanPaths): array
    {
        if ($scanPaths !== []) {
            return $scanPaths;
        }

        $paths = [];

        foreach ($layers as $layerPaths) {
            foreach ((array) $layerPaths as $layerPath) {
                $paths[] = $layerPath;
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $skipPaths
     */
    private function isSkipped(string $path, array $skipPaths): bool
    {
        $path = $this->normalisePath($path);
        $relativePath = $this->relativePath($path);

        foreach ($skipPaths as $skipPath) {
            if (
                $this->matchesSkipPath($relativePath, $skipPath)
                || $this->matchesSkipPath($path, $skipPath)
            ) {
                return true;
            }
        }

        return false;
    }

    private function matchesSkipPath(string $path, string $skipPath): bool
    {
        $skipPath = $this->normaliseSkipPath($skipPath);

        if (fnmatch($skipPath, $path)) {
            return true;
        }

        if (strpbrk($skipPath, '*?[') !== false) {
            return false;
        }

        $fullSkipPath = rtrim($this->basePath, '/') . '/' . ltrim($skipPath, '/');
        $fullSkipPath = $this->normalisePath($fullSkipPath);
        $normalisedPath = $this->normalisePath($path);

        return $normalisedPath === $fullSkipPath
            || str_starts_with($normalisedPath, $fullSkipPath . '/')
            || $path === $skipPath
            || str_starts_with($path, rtrim($skipPath, '/') . '/');
    }

    private function normaliseSkipPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function relativePath(string $path): string
    {
        $basePath = $this->normalisePath($this->basePath);

        if ($path === $basePath) {
            return '';
        }

        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    private function normalisePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }

    /**
     * @return string[]
     */
    private function phpFiles(string $path): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();

            if ($file->getExtension() === 'php' && $realPath !== false) {
                $files[] = $realPath;
            }
        }

        return $files;
    }
}
