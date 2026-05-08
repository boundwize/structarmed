<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Config;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    public function testLoadReturnsArchitecture(): void
    {
        $path = $this->writeTempConfig('return ' . Architecture::class . '::define();');

        $this->assertInstanceOf(Architecture::class, ConfigLoader::load($path));
    }

    public function testLoadThrowsWhenConfigIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StructArmed config file not found');

        ConfigLoader::load('/private/tmp/structarmed-missing-config.php');
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
        $path = tempnam('/private/tmp', 'structarmed-config-');
        self::assertIsString($path);
        file_put_contents($path, "<?php\n\n" . $body . "\n");

        return $path;
    }

    private function makeTempDir(): string
    {
        $path = '/private/tmp/structarmed-' . bin2hex(random_bytes(6));
        mkdir($path);

        return $path;
    }
}
