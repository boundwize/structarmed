<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;

$autoloaderPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

foreach ($autoloaderPaths as $autoloader) {
    if (file_exists($autoloader)) {
        require $autoloader;
        break;
    }
}

exit(ClassNodeWorker::run($argv[1] ?? '', $argv[2] ?? ''));
