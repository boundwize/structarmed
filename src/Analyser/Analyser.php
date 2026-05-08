<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Error;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Analyser
{
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath ?: getcwd();
    }

    public function analyse(Architecture $architecture): RuleViolationCollection
    {
        $violations = new RuleViolationCollection();
        $resolver   = new ChainLayerResolver(
            new NamespaceLayerResolver($architecture->getLayers(), $this->basePath)
        );

        $collector = new ClassCollector($resolver);
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($architecture->getLayers() as $layerName => $layerPath) {
            $fullPath = rtrim($this->basePath, '/') . '/' . ltrim($layerPath, '/');

            if (! is_dir($fullPath)) {
                continue;
            }

            foreach ($this->phpFiles($fullPath) as $file) {
                try {
                    $code = (string) file_get_contents($file);
                    $ast  = $parser->parse($code);

                    if ($ast === null) {
                        continue;
                    }

                    $collector->setCurrentFile($file);

                    $traverser = new NodeTraverser();
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
            if ($file->getExtension() === 'php') {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }
}
