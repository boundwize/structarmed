<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Report\Reports\ConsoleReport;
use Boundwize\StructArmed\Report\Reports\JsonReport;

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

// Parse CLI arguments
$options = getopt('', [
    'config:',
    'report:',
    'path:',
    'baseline:',
]);

$basePath   = getcwd();
$command    = $argv[1] ?? null;

$printUsage = static function (): void {
    echo <<<'TXT'
Usage:
  structarmed init
  structarmed analyse|analyze [path ...] [--config=path/to/structarmed.php] [--report=console|json]

TXT;
};

if ($command === null || $command === '--help' || $command === '-h') {
    $printUsage();
    exit(0);
}

// Handle `init` command — generate a sample config
if ($command === 'init') {
    $target = $basePath . '/structarmed.php';

    if (file_exists($target)) {
        echo "structarmed.php already exists.\n";
        exit(0);
    }

    file_put_contents($target, <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->withPreset(Preset::PSR4());
PHP);

    echo "Created structarmed.php\n";
    exit(0);
}

if (! in_array($command, ['analyse', 'analyze'], true)) {
    echo sprintf("Unknown command: %s\n\n", $command);
    $printUsage();
    exit(1);
}

$reportType = $options['report'] ?? 'console';
$scanPaths = [];
$skipNextOptionValue = false;

foreach (array_slice($argv, 2) as $argument) {
    if ($skipNextOptionValue) {
        $skipNextOptionValue = false;
        continue;
    }

    if (in_array($argument, ['--config', '--report'], true)) {
        $skipNextOptionValue = true;
        continue;
    }

    if (str_starts_with($argument, '--')) {
        continue;
    }

    $scanPaths[] = $argument;
}

foreach ($scanPaths as $scanPath) {
    $fullScanPath = rtrim($basePath, '/') . '/' . ltrim($scanPath, '/');

    if (! is_dir($fullScanPath)) {
        echo sprintf("Error: directory [%s] not found.\n", $scanPath);
        exit(1);
    }
}

// Load config
try {
    $configFile   = $options['config'] ?? ConfigLoader::discover($basePath);
    $architecture = ConfigLoader::load($configFile);
} catch (RuntimeException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Run analysis
$start    = microtime(true);
$analyser = new Analyser($basePath);
$violations = $analyser->analyse($architecture, $scanPaths);
$elapsed  = microtime(true) - $start;

// Render report
$report = match ($reportType) {
    'json'    => (new JsonReport())->render($violations, $elapsed),
    default   => (new ConsoleReport())->render($violations, $elapsed),
};

echo $report;

exit($violations->hasViolations() ? 1 : 0);
