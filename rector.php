<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\MethodCall\AssertTrueFalseToSpecificMethodRector;

return RectorConfig::configure()
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
    ->withImportNames()
    ->withSkip([
        // buggy on assertDirectoryNotExists not exists method
        AssertTrueFalseToSpecificMethodRector::class,
    ]);
