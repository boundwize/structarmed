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

#[CoversClass(Psr4PathResolver::class)]
final class Psr4PathResolverTest extends TestCase
{
    public function testReturnsEmptyPathsWhenComposerJsonIsMissing(): void
    {
        $resolver = new Psr4PathResolver();

        $this->assertSame([], $resolver->paths($this->makeTempDir()));
        $this->assertSame([], $resolver->namespacePaths($this->makeTempDir()));
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
            "Root\\": "."
        }
    },
    "autoload-dev": {
        "psr-4": {
            "": "legacy/",
            "Broken\\": [false]
        }
    }
}
JSON);

        $resolver = new Psr4PathResolver();

        $this->assertSame(['src', '.', 'legacy'], $resolver->paths($basePath));
        $this->assertSame(
            [
                'App\\' => ['src'],
                'Root\\' => ['.'],
                '' => ['legacy'],
            ],
            $resolver->namespacePaths($basePath)
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

        $resolver = new Psr4PathResolver();

        $this->assertSame([], $resolver->paths($basePath));
        $this->assertSame([], $resolver->namespacePaths($basePath));
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTempDir();
        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }

    private function makeTempDir(): string
    {
        $basePath = '/private/tmp/structarmed-psr4-resolver-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }
}
