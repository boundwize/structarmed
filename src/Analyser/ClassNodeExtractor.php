<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function array_values;
use function file_get_contents;

final readonly class ClassNodeExtractor
{
    private ClassCollector $classCollector;
    private Parser $parser;

    public function __construct(
        private LayerResolverInterface $layerResolver,
    ) {
        $this->classCollector = new ClassCollector($this->layerResolver);
        $this->parser         = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param list<string> $files
     * @return list<ClassNode>
     */
    public function extract(array $files, ?ProgressHandlerInterface $progressHandler = null): array
    {
        foreach ($files as $file) {
            try {
                $code = (string) file_get_contents($file);
                $ast  = $this->parser->parse($code);

                if ($ast === null || $ast === []) {
                    continue;
                }

                $this->classCollector->setCurrentFile($file);

                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $traverser->addVisitor($this->classCollector);
                $traverser->traverse($ast);
            } catch (Error) {
                // Skip files with parse errors
            } finally {
                $progressHandler?->advance($file);
            }
        }

        return array_values($this->classCollector->getNodes());
    }
}
