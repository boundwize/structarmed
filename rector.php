<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;

return RectorConfig::configure()
    ->withSkip([
        RenameForeachValueVariableToMatchExprVariableRector::class => [
            __DIR__ . '/src/Analyser/Analyser.php',
        ],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        codeQuality: true,
        codingStyle: true,
        deadCode: true,
        naming: true,
        privatization: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        phpunitCodeQuality: true
    )
    ->withComposerBased(phpunit: true)
    ->withParallel()
    ->withRootFiles()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withImportNames(removeUnusedImports: true);
