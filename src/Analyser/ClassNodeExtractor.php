<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

use function array_values;
use function file_get_contents;

final readonly class ClassNodeExtractor
{
    public function __construct(
        private LayerResolverInterface $layerResolver,
    ) {
    }

    /**
     * @param list<string> $files
     * @return list<ClassNode>
     */
    public function extract(array $files, ?ProgressHandlerInterface $progressHandler = null): array
    {
        $classCollector = new ClassCollector($this->layerResolver);
        $parser         = (new ParserFactory())->createForNewestSupportedVersion();

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

        return array_values($classCollector->getNodes());
    }
}
