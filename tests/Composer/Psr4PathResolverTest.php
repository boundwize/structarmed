<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Composer;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(Psr4PathResolver::class)]
final class Psr4PathResolverTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

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

        $this->assertSame(['src', 'system', 'tests/system', 'legacy'], $psr4PathResolver->paths($basePath));
        $this->assertSame(
            [
                'App\\'         => ['src'],
                'CodeIgniter\\' => ['system', 'tests/system'],
                ''              => ['legacy'],
            ],
            $psr4PathResolver->namespacePaths($basePath)
        );
    }

    public function testMergesPathsWhenSameNamespaceAppearsInAutoloadAndAutoloadDev(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "CodeIgniter\\": "system/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CodeIgniter\\": "tests/system/"
        }
    }
}
JSON);

        $psr4PathResolver = new Psr4PathResolver();

        $this->assertSame(['system', 'tests/system'], $psr4PathResolver->paths($basePath));
        $this->assertSame(
            ['CodeIgniter\\' => ['system', 'tests/system']],
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
        return $this->makeTemporaryDirectory('structarmed-psr4-resolver');
    }
}
