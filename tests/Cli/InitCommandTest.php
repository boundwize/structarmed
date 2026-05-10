<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function chdir;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_get_contents;
use function getcwd;
use function implode;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;

final class InitCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function presetProvider(): iterable
    {
        yield 'default' => [
            [],
            '    ->withPreset(Preset::PSR4());',
        ];

        yield 'ddd' => [
            ['--preset=ddd'],
            '    ->withPreset(Preset::DDD());',
        ];

        yield 'mvc' => [
            ['--preset=mvc'],
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
    public function testInitGeneratesConfigForPreset(array $arguments, string $expectedPreset): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runInit($basePath, $arguments);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Created structarmed.php', $output);
            $this->assertSame(
                $this->expectedConfig($expectedPreset),
                (string) file_get_contents($basePath . '/structarmed.php')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testInitRejectsInvalidPreset(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runInit($basePath, ['--preset=unknown']);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Invalid preset: unknown', $output);
            $this->assertFileDoesNotExist($basePath . '/structarmed.php');
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runInit(string $basePath, array $arguments): array
    {
        $previousDirectory = getcwd();
        $output            = [];
        $exitCode          = 0;
        $command           = sprintf(
            '%s %s init %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/structarmed.php'),
            implode(' ', array_map(escapeshellarg(...), $arguments))
        );

        chdir($basePath);

        try {
            exec($command . ' 2>&1', $output, $exitCode);
        } finally {
            chdir((string) $previousDirectory);
        }

        return [$exitCode, implode("\n", $output)];
    }

    private function createTempDirectory(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-init-' . bin2hex(random_bytes(6));
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
        if (file_exists($basePath . '/structarmed.php')) {
            unlink($basePath . '/structarmed.php');
        }

        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
}
