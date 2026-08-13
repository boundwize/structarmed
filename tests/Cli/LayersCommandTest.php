<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cli;

use Boundwize\StructArmed\Cli\LayersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(LayersCommand::class)]
final class LayersCommandTest extends TestCase
{
    public function testListsPathAndPatternLayersIncludingPresetLayers(): void
    {
        $basePath = $this->createTempDirectory();
        $this->writeConfig($basePath, <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->layer('Domain', 'src/Domain/')
    ->layer('Application', ['src/Application/', 'src/App/'])
    ->layerPattern('API', '/^App\\\\API\\\\.*$/')
    ->withPreset(Preset::MVC());
PHP);

        try {
            [$exitCode, $output] = $this->runLayers([], $basePath);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Domain', $output);
            $this->assertStringContainsString('src/Domain/', $output);
            $this->assertStringContainsString('Application', $output);
            $this->assertStringContainsString('src/Application/, src/App/', $output);
            $this->assertStringContainsString('API', $output);
            $this->assertStringContainsString('/^App\\\\API\\\\.*$/', $output);
            $this->assertStringContainsString('Controller', $output);
            $this->assertStringContainsString('src/Controller/', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testReportsNoLayersRegistered(): void
    {
        $basePath = $this->createTempDirectory();
        $this->writeConfig($basePath, <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define();
PHP);

        try {
            [$exitCode, $output] = $this->runLayers([], $basePath);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('No layers registered.', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testUsesExplicitConfigWithEqualsOption(): void
    {
        $basePath = $this->createTempDirectory();
        $this->writeConfig($basePath, <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Domain', 'src/Domain/');
PHP);

        try {
            [$exitCode, $output] = $this->runLayers(['--config=' . $basePath . '/structarmed.php'], $basePath);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Domain', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testUsesExplicitConfigWithSeparateOptionValue(): void
    {
        $basePath = $this->createTempDirectory();
        $this->writeConfig($basePath, <<<'PHP'
<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    ->layer('Domain', 'src/Domain/');
PHP);

        try {
            [$exitCode, $output] = $this->runLayers(['--config', $basePath . '/structarmed.php'], $basePath);

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Domain', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testRejectsUnknownOption(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runLayers(['--bogus'], $basePath);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Unknown option: --bogus', $output);
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testReportsMissingConfig(): void
    {
        $basePath = $this->createTempDirectory();

        try {
            [$exitCode, $output] = $this->runLayers([], $basePath);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString(
                'Could not find a structarmed.php config file.',
                $output
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runLayers(array $arguments, string $basePath): array
    {
        ob_start();
        $exitCode = (new LayersCommand())->run($arguments, $basePath);
        $output   = ob_get_contents();
        ob_end_clean();

        return [$exitCode, (string) $output];
    }

    private function writeConfig(string $basePath, string $config): void
    {
        file_put_contents($basePath . '/structarmed.php', $config);
    }

    private function createTempDirectory(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-layers-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
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
