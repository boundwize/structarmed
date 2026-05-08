<?php

declare(strict_types=1);

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Progress\ConsoleProgressBar;
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

$basePath = getcwd();
$command  = $argv[1] ?? null;

$printUsage = static function (): void {
    echo <<<'TXT'
Usage:
  structarmed init [--preset=ddd|mvc|psr4|all]
  structarmed analyse|analyze [path ...] [--config=path/to/structarmed.php] [--report=console|json] [--no-progress]

TXT;
};

if ($command === null || $command === '--help' || $command === '-h') {
    $printUsage();
    exit(0);
}

// Handle `init` command — generate a sample config
if ($command === 'init') {
    $preset    = 'psr4';
    $arguments = array_slice($argv, 2);

    for ($i = 0; $i < count($arguments); $i++) {
        $argument = $arguments[$i];

        if (str_starts_with($argument, '--preset=')) {
            $preset = strtolower(substr($argument, strlen('--preset=')));
            continue;
        }

        if ($argument === '--preset') {
            $preset = strtolower($arguments[++$i] ?? '');
            continue;
        }

        echo sprintf("Unknown option: %s\n\n", $argument);
        $printUsage();
        exit(1);
    }

    $presetConfig = match ($preset) {
        'ddd' => '    ->withPreset(Preset::DDD());',
        'mvc' => '    ->withPreset(Preset::MVC());',
        'psr4' => '    ->withPreset(Preset::PSR4());',
        'all' => '    ->withPresets(Preset::PSR4(), Preset::DDD(), Preset::MVC());',
        default => null,
    };

    if ($presetConfig === null) {
        echo sprintf("Invalid preset: %s\n\n", $preset);
        $printUsage();
        exit(1);
    }

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
PHP);
    file_put_contents($target, "\n" . $presetConfig . "\n", FILE_APPEND);

    echo "Created structarmed.php\n";
    exit(0);
}

if (! in_array($command, ['analyse', 'analyze'], true)) {
    echo sprintf("Unknown command: %s\n\n", $command);
    $printUsage();
    exit(1);
}

$options   = [];
$scanPaths = [];
$arguments = array_slice($argv, 2);

for ($i = 0; $i < count($arguments); $i++) {
    $argument = $arguments[$i];

    if (str_starts_with($argument, '--report=')) {
        $options['report'] = substr($argument, strlen('--report='));
        continue;
    }

    if ($argument === '--report') {
        $options['report'] = $arguments[++$i] ?? '';
        continue;
    }

    if (str_starts_with($argument, '--config=')) {
        $options['config'] = substr($argument, strlen('--config='));
        continue;
    }

    if ($argument === '--config') {
        $options['config'] = $arguments[++$i] ?? '';
        continue;
    }

    if ($argument === '--no-progress') {
        $options['progress'] = false;
        continue;
    }

    if (str_starts_with($argument, '--')) {
        echo sprintf("Unknown option: %s\n\n", $argument);
        $printUsage();
        exit(1);
    }

    $scanPaths[] = $argument;
}

$reportType = $options['report'] ?? 'console';

if (! in_array($reportType, ['console', 'json'], true)) {
    echo sprintf("Invalid report type: %s\n\n", $reportType);
    $printUsage();
    exit(1);
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
$start      = microtime(true);
$analyser   = new Analyser($basePath);
$progress   = $reportType === 'console' && ($options['progress'] ?? true) === true
    ? new ConsoleProgressBar()
    : null;
$violations = $analyser->analyse($architecture, $scanPaths, $progress);
$elapsed    = microtime(true) - $start;

// Render report
$report = match ($reportType) {
    'json'    => (new JsonReport())->render($violations, $elapsed),
    default   => (new ConsoleReport())->render($violations, $elapsed),
};

echo $report;

exit($violations->hasViolations() ? 1 : 0);
