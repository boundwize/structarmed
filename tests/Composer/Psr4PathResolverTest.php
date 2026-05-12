<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Composer;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(Psr4PathResolver::class)]
final class Psr4PathResolverTest extends TestCase
{
    public function testReturnsEmptyPathsWhenComposerJsonIsMissing(): void
    {
        $psr4PathResolver = new Psr4PathResolver();

        $this->assertSame([], $psr4PathResolver->paths($this->makeTempDir()));
        $this->assertSame([], $psr4PathResolver->namespacePaths($this->makeTempDir()));
    }

    public function testReturnsNullComposerConfigWhenComposerJsonIsInvalid(): void
    {
        $basePath = $this->makeTempProject('{not json');

        $this->assertNull((new Psr4PathResolver())->composerConfig($basePath));
    }

    public function testReturnsNullComposerConfigWhenRootJsonIsNotObject(): void
    {
        $basePath = $this->makeTempProject('["not", "an", "object"]');

        $this->assertNull((new Psr4PathResolver())->composerConfig($basePath));
    }

    public function testReadsPathsAndNamespacePathsFromComposerPsr4Mappings(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": ["src/", "", "src\\"],
            "CodeIgniter\\": "system/",
            "Root\\": "."
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CodeIgniter\\": "tests/system/",
            "": "legacy/",
            "Broken\\": [false]
        }
    }
}
JSON);

        $psr4PathResolver = new Psr4PathResolver();

        $this->assertSame(['src', 'system', 'tests/system', '.', 'legacy'], $psr4PathResolver->paths($basePath));
        $this->assertSame(
            [
                'App\\'         => ['src'],
                'CodeIgniter\\' => ['system', 'tests/system'],
                'Root\\'        => ['.'],
                ''              => ['legacy'],
            ],
            $psr4PathResolver->namespacePaths($basePath)
        );
    }

    public function testSkipsInvalidAutoloadShapes(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": "invalid",
    "autoload-dev": {
        "psr-4": "invalid"
    }
}
JSON);

        $psr4PathResolver = new Psr4PathResolver();

        $this->assertSame([], $psr4PathResolver->paths($basePath));
        $this->assertSame([], $psr4PathResolver->namespacePaths($basePath));
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTempDir();
        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }

    private function makeTempDir(): string
    {
        $basePath = sys_get_temp_dir() . '/structarmed-psr4-resolver-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }
}
