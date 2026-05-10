<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cli\AnalyseCommand;
use Boundwize\StructArmed\Cli\ClearCacheCommand;
use Boundwize\StructArmed\Cli\InitCommand;
use Boundwize\StructArmed\Cli\StructArmedApplication;
use Boundwize\StructArmed\Cli\Usage;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function random_bytes;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(AnalyseCommand::class)]
#[CoversClass(ClearCacheCommand::class)]
#[CoversClass(InitCommand::class)]
#[CoversClass(StructArmedApplication::class)]
#[CoversClass(Usage::class)]
final class StructArmedApplicationTest extends TestCase
{
    public function testApplicationPrintsUsageWithoutCommand(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('structarmed init', $output);
        $this->assertStringContainsString('structarmed analyse|analyze', $output);
    }

    public function testApplicationRejectsUnknownCommand(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'unknown']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown command: unknown', $output);
    }

    public function testApplicationClearsCacheWithoutAnalyseCommand(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', '--clear-cache']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('StructArmed cache cleared.', $output);
    }

    public function testApplicationClearsConfiguredCacheWithoutAnalyseCommand(): void
    {
        $basePath       = $this->createProjectDirectory();
        $cacheDirectory = $basePath . '/var/cache/structarmed';

        try {
            mkdir($basePath . '/var');
            mkdir($basePath . '/var/cache');
            mkdir($cacheDirectory);

            file_put_contents($cacheDirectory . '/key.json', '{}');
            file_put_contents($basePath . '/structarmed-custom.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed');
PHP);

            [$exitCode, $output] = $this->runApplication(
                ['structarmed', '--clear-cache', '--config=' . $basePath . '/structarmed-custom.php'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('StructArmed cache cleared.', $output);
            $this->assertFalse(is_dir($cacheDirectory));
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testApplicationClearsConfiguredCacheWithSeparateConfigOption(): void
    {
        $basePath       = $this->createProjectDirectory();
        $cacheDirectory = $basePath . '/var/cache/structarmed';

        try {
            mkdir($basePath . '/var');
            mkdir($basePath . '/var/cache');
            mkdir($cacheDirectory);

            file_put_contents($cacheDirectory . '/key.json', '{}');
            file_put_contents($basePath . '/structarmed-custom.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed');
PHP);

            [$exitCode, $output] = $this->runApplication(
                ['structarmed', '--clear-cache', '--config', $basePath . '/structarmed-custom.php'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('StructArmed cache cleared.', $output);
            $this->assertFalse(is_dir($cacheDirectory));
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testApplicationReportsInvalidClearCacheConfig(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', '--clear-cache', '--config=' . $basePath . '/missing.php'],
                $basePath
            );

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('StructArmed config file not found', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testApplicationRejectsUnknownClearCacheOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', '--clear-cache', '--bad-option']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function presetProvider(): iterable
    {
        yield 'default' => [
            [],
            '    ->withPreset(Preset::PSR4());',
        ];

        yield 'ddd with equals option' => [
            ['--preset=ddd'],
            '    ->withPreset(Preset::DDD());',
        ];

        yield 'mvc with separate option' => [
            ['--preset', 'mvc'],
            '    ->withPreset(Preset::MVC());',
        ];

        yield 'psr1' => [
            ['--preset=psr1'],
            '    ->withPreset(Preset::PSR1());',
        ];

        yield 'psr4' => [
            ['--preset=psr4'],
            '    ->withPreset(Preset::PSR4());',
        ];

        yield 'all' => [
            ['--preset=all'],
            '    ->withPresets(Preset::PSR1(), Preset::PSR4(), Preset::DDD(), Preset::MVC());',
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('presetProvider')]
    public function testInitCommandGeneratesConfig(array $arguments, string $expectedPreset): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'init', ...$arguments], $basePath);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Created structarmed.php', $output);
            $this->assertSame(
                $this->expectedConfig($expectedPreset),
                file_get_contents($basePath . '/structarmed.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testInitCommandReportsExistingConfig(): void
    {
        $basePath = $this->createTempDirectory();
        file_put_contents($basePath . '/structarmed.php', '<?php return null;');

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'init'], $basePath);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('structarmed.php already exists.', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testInitCommandRejectsUnknownOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'init', '--bad-option']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    public function testInitCommandRejectsInvalidPreset(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'init', '--preset=unknown']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid preset: unknown', $output);
    }

    public function testAnalyseCommandRendersConsoleReport(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyse', 'src', '--config', $basePath . '/structarmed.php', '--no-progress'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('StructArmed', $output);
            $this->assertStringContainsString('No violations found', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandUsesProgressForConsoleReport(): void
    {
        $basePath = $this->createProjectDirectory();
        $progress = new class implements ProgressHandlerInterface {
            public bool $started = false;

            public bool $finished = false;

            public function start(int $total): void
            {
                $this->started = true;
            }

            public function advance(string $file): void
            {
            }

            public function finish(): void
            {
                $this->finished = true;
            }
        };

        try {
            [$exitCode, $output] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('No violations found', $output);
            $this->assertTrue($progress->started);
            $this->assertTrue($progress->finished);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandCanReuseCachedResult(): void
    {
        $basePath = $this->createProjectDirectory();
        $progress = new class implements ProgressHandlerInterface {
            public bool $started = false;

            public function start(int $total): void
            {
                $this->started = true;
            }

            public function advance(string $file): void
            {
            }

            public function finish(): void
            {
            }
        };

        try {
            [$firstExitCode, $firstOutput] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            [$secondExitCode, $secondOutput] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $firstExitCode, $firstOutput);
            $this->assertSame(0, $secondExitCode, $secondOutput);
            $this->assertStringContainsString('No violations found', $secondOutput);
            $this->assertFalse($progress->started);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandOnlyParsesNewFilesAfterCacheWarmup(): void
    {
        $basePath = $this->createProjectDirectory();
        mkdir($basePath . '/src/Domain');
        file_put_contents($basePath . '/src/Foo.php', <<<'PHP'
<?php namespace App; final class Foo { public function run(): void {} }
PHP);
        file_put_contents($basePath . '/src/Bar.php', '<?php namespace App; final class Bar {}');
        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Source', 'src/');
PHP);

        $progress = new class implements ProgressHandlerInterface {
            public int $total = 0;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        try {
            [$firstExitCode, $firstOutput] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            file_put_contents($basePath . '/src/Baz.php', '<?php namespace App; final class Baz {}');

            [$secondExitCode, $secondOutput] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $firstExitCode, $firstOutput);
            $this->assertSame(0, $secondExitCode, $secondOutput);
            $this->assertSame(1, $progress->total);
            $this->assertCount(1, $progress->files);
            $this->assertStringEndsWith('/src/Baz.php', $this->normalisePath($progress->files[0]));
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    private function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public function testAnalyseCommandClearsEntireCacheWhenComposerLockChanges(): void
    {
        $basePath = $this->createTempDirectory();
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', '<?php namespace App; final class Foo {}');
        file_put_contents($basePath . '/composer.lock', '{"packages":[]}');
        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Source', 'src/');
PHP);

        $progress = new class implements ProgressHandlerInterface {
            public int $total = 0;

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
            }

            public function finish(): void
            {
            }
        };

        try {
            $this->runApplication(
                ['structarmed', 'analyse', '--config=' . $basePath . '/structarmed.php', '--no-progress'],
                $basePath
            );

            file_put_contents(
                $basePath . '/composer.lock',
                '{"packages":[{"name":"some/package","version":"2.0.0"}]}'
            );

            [$exitCode, $output] = $this->runAnalyseCommand(
                ['--config=' . $basePath . '/structarmed.php'],
                $basePath,
                $progress
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertGreaterThan(0, $progress->total);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandStoresResultInConfiguredCacheDirectory(): void
    {
        $basePath       = $this->createProjectDirectory();
        $cacheDirectory = $basePath . '/var/cache/structarmed';

        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->cacheDirectory('var/cache/structarmed');
PHP);

        try {
            [$exitCode, $output] = $this->runApplication(
                [
                    'structarmed',
                    'analyse',
                    '--config=' . $basePath . '/structarmed.php',
                    '--clear-cache',
                    '--no-progress',
                ],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertTrue(is_dir($cacheDirectory));
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandRendersJsonReport(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyze', 'src', '--config=' . $basePath . '/structarmed.php', '--report=json'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);

            $data = json_decode($output, true);
            $this->assertIsArray($data);
            $this->assertSame(0, $data['total']);
            $this->assertTrue($data['passed']);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandAcceptsSeparateReportOption(): void
    {
        $basePath = $this->createProjectDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(
                ['structarmed', 'analyse', '--config=' . $basePath . '/structarmed.php', '--report', 'json'],
                $basePath
            );

            $this->assertSame(0, $exitCode, $output);
            $this->assertJson($output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testAnalyseCommandRejectsInvalidReportType(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse', '--report=xml']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid report type: xml', $output);
    }

    public function testAnalyseCommandRejectsUnknownOption(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse', '--bad-option']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown option: --bad-option', $output);
    }

    public function testAnalyseCommandRejectsMissingScanPath(): void
    {
        [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse', 'missing']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error: directory [missing] not found.', $output);
    }

    public function testAnalyseCommandReportsMissingConfig(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runApplication(['structarmed', 'analyse'], $basePath);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Could not find a structarmed.php config file', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    /**
     * @param list<string> $argv
     * @return array{int, string}
     */
    private function runApplication(array $argv, ?string $basePath = null): array
    {
        ob_start();
        $exitCode = (new StructArmedApplication())->run($argv, $basePath);
        $output   = ob_get_clean();
        $this->assertIsString($output);

        return [$exitCode, $output];
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runAnalyseCommand(
        array $arguments,
        string $basePath,
        ProgressHandlerInterface $progressHandler
    ): array {
        ob_start();
        $exitCode = (new AnalyseCommand($progressHandler))->run($arguments, $basePath);
        $output   = ob_get_clean();
        $this->assertIsString($output);

        return [$exitCode, $output];
    }

    private function createProjectDirectory(): string
    {
        $basePath = $this->createTempDirectory();
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/structarmed.php', <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define();
PHP);

        return $basePath;
    }

    private function createTempDirectory(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-cli-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }

    private function expectedConfig(string $presetConfig): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
{$presetConfig}

PHP;
    }

    private function removeTempDirectory(string $basePath): void
    {
        (new AnalysisResultCache($basePath))->clear();

        if (file_exists($basePath . '/structarmed.php')) {
            unlink($basePath . '/structarmed.php');
        }

        if (file_exists($basePath . '/structarmed-custom.php')) {
            unlink($basePath . '/structarmed-custom.php');
        }

        if (file_exists($basePath . '/composer.lock')) {
            unlink($basePath . '/composer.lock');
        }

        foreach (glob($basePath . '/var/cache/structarmed/*.json') ?: [] as $cacheFile) {
            unlink($cacheFile);
        }

        foreach (glob($basePath . '/src/*.php') ?: [] as $sourceFile) {
            unlink($sourceFile);
        }

        if (is_dir($basePath . '/src/Domain')) {
            rmdir($basePath . '/src/Domain');
        }

        if (is_dir($basePath . '/src')) {
            rmdir($basePath . '/src');
        }

        if (is_dir($basePath . '/var/cache/structarmed')) {
            rmdir($basePath . '/var/cache/structarmed');
        }

        if (is_dir($basePath . '/var/cache')) {
            rmdir($basePath . '/var/cache');
        }

        if (is_dir($basePath . '/var')) {
            rmdir($basePath . '/var');
        }

        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
}
