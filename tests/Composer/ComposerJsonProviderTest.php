<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Composer;

use Boundwize\StructArmed\Composer\ComposerJsonProvider;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(ComposerJsonProvider::class)]
final class ComposerJsonProviderTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testReturnsNullWhenComposerJsonIsMissingInvalidOrNotObject(): void
    {
        $composerJsonProvider = new ComposerJsonProvider();

        $this->assertNull($composerJsonProvider->config($this->makeTempDir()));
        $this->assertNull($composerJsonProvider->config($this->makeTempProject('{not json')));
        $this->assertNull($composerJsonProvider->config($this->makeTempProject('["not", "an", "object"]')));
    }

    public function testMemoisesDecodedComposerJsonAcrossInstancesUntilCleared(): void
    {
        $basePath             = $this->makeTempProject('{"name": "app/first"}');
        $composerJsonProvider = new ComposerJsonProvider();

        $this->assertSame(['name' => 'app/first'], $composerJsonProvider->config($basePath));

        // Same byte length, rewritten immediately: the memo is lifecycle-bound,
        // not tied to filesystem metadata, so it is served until cleared.
        file_put_contents($basePath . '/composer.json', '{"name": "app/other"}');

        $this->assertSame(['name' => 'app/first'], (new ComposerJsonProvider())->config($basePath . '/'));

        $composerJsonProvider->clear();

        $this->assertSame(['name' => 'app/other'], $composerJsonProvider->config($basePath));
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTempDir();

        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }

    private function makeTempDir(): string
    {
        return $this->makeTemporaryDirectory('structarmed-composer-json-provider');
    }
}
