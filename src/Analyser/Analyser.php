<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
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

use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function fnmatch;
use function getcwd;
use function is_dir;
use function ltrim;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function substr;

final readonly class Analyser
{
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
    }

    /**
     * @param list<string> $scanPaths
     */
    public function analyse(
        Architecture $architecture,
        array $scanPaths = [],
        ?ProgressHandlerInterface $progressHandler = null
    ): RuleViolationCollection {
        $ruleViolationCollection = new RuleViolationCollection();
        $layers                  = $this->resolveLayers($architecture);
        $skipPaths               = $architecture->getSkipPaths();
        $ruleSkipPaths           = $architecture->getRuleSkipPaths();

        foreach ($architecture->getRules() as $key => $rule) {
            if (! $rule instanceof ProjectRuleInterface) {
                continue;
            }

            $violation = $rule->evaluateProject($this->basePath, $architecture);

            if (! $violation instanceof RuleViolation) {
                continue;
            }

            $ruleViolationCollection->add(new RuleViolation(
                ruleKey:   $key,
                message:   $violation->message,
                file:      $violation->file,
                line:      $violation->line,
                className: $violation->className,
                layer:     $violation->layer,
            ));
        }

        $chainLayerResolver = new ChainLayerResolver(
            new NamespaceLayerResolver($layers, $this->basePath)
        );

        $classCollector = new ClassCollector($chainLayerResolver);
        $parser         = (new ParserFactory())->createForNewestSupportedVersion();
        $files          = $this->collectPhpFiles($layers, $scanPaths, $skipPaths);

        $progressHandler?->start(count($files));

        foreach ($files as $file) {
            try {
                $code = (string) file_get_contents($file);
                $ast  = $parser->parse($code);

                if ($ast === null || $ast === []) {
                    continue;
                }

                $classCollector->setCurrentFile($file);

                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $traverser->addVisitor($classCollector);
                $traverser->traverse($ast);
            } catch (Error) {
                // Skip files with parse errors
            } finally {
                $progressHandler?->advance($file);
            }
        }

        $progressHandler?->finish();

        $rules = $architecture->getRules();

        foreach ($classCollector->getNodes() as $classNode) {
            foreach ($rules as $key => $rule) {
                if (! $rule instanceof RuleInterface) {
                    continue;
                }

                if ($this->isSkipped($classNode->file, $ruleSkipPaths[$key] ?? [])) {
                    continue;
                }

                if (! $rule->appliesTo($classNode)) {
                    continue;
                }

                $violations = $rule instanceof MultipleRuleViolationInterface
                    ? $rule->evaluateAll($classNode)
                    : [$rule->evaluate($classNode)];

                foreach ($violations as $violation) {
                    if (! $violation instanceof RuleViolation) {
                        continue;
                    }

                    // Inject the rule key into the violation
                    $ruleViolationCollection->add(new RuleViolation(
                        ruleKey:   $key,
                        message:   $violation->message,
                        file:      $violation->file,
                        line:      $violation->line,
                        className: $violation->className,
                        layer:     $violation->layer,
                    ));
                }
            }
        }

        return $ruleViolationCollection;
    }

    /**
     * @param array<string, string|list<string>> $layers
     * @param list<string> $scanPaths
     * @param list<string> $skipPaths
     * @return list<string>
     */
    private function collectPhpFiles(array $layers, array $scanPaths, array $skipPaths): array
    {
        $files = [];

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

                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function resolveLayers(Architecture $architecture): array
    {
        $layers = $architecture->getLayers();

        foreach ($architecture->getRules() as $rule) {
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
            return array_values(array_unique($scanPaths));
        }

        $paths = [];

        foreach ($layers as $layer) {
            foreach ((array) $layer as $layerPath) {
                $paths[] = $layerPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $skipPaths
     */
    private function isSkipped(string $path, array $skipPaths): bool
    {
        $path         = $this->normalisePath($path);
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
