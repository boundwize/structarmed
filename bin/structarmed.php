<?php

declare(strict_types=1);

use Boundwize\StructArmed\Cli\StructArmedApplication;

// Auto-discover autoloader
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

exit((new StructArmedApplication())->run($argv));
