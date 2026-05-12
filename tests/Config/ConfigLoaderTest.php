<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Config;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_put_contents;
use function sys_get_temp_dir;
use function touch;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testLoadReturnsArchitecture(): void
    {
        $path = $this->writeTempConfig('return ' . Architecture::class . '::define();');

        $this->assertInstanceOf(Architecture::class, ConfigLoader::load($path));
    }

    public function testLoadThrowsWhenConfigIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StructArmed config file not found');

        ConfigLoader::load(sys_get_temp_dir() . '/structarmed-missing-config.php');
    }

    public function testLoadThrowsWhenConfigReturnsWrongType(): void
    {
        $path = $this->writeTempConfig('return [];');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return an instance of ' . Architecture::class);

        ConfigLoader::load($path);
    }

    public function testDiscoverPrefersProjectConfig(): void
    {
        $basePath = $this->makeTempDir();
        touch($basePath . '/structarmed.php');
        touch($basePath . '/structarmed.dist.php');

        $this->assertSame($basePath . '/structarmed.php', ConfigLoader::discover($basePath));
    }

    public function testDiscoverFallsBackToDistConfig(): void
    {
        $basePath = $this->makeTempDir();
        touch($basePath . '/structarmed.dist.php');

        $this->assertSame($basePath . '/structarmed.dist.php', ConfigLoader::discover($basePath));
    }

    public function testDiscoverThrowsWhenNoConfigExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not find a structarmed.php config file');

        ConfigLoader::discover($this->makeTempDir());
    }

    private function writeTempConfig(string $body): string
    {
        $path = $this->makeTemporaryFile('structarmed-config');
        file_put_contents($path, "<?php\n\n" . $body . "\n");

        return $path;
    }

    private function makeTempDir(): string
    {
        return $this->makeTemporaryDirectory('structarmed');
    }
}
