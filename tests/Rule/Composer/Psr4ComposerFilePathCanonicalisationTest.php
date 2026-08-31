<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4EmptyNamespacePrefixRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4RootPathRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function realpath;
use function str_replace;

#[CoversClass(Psr4EmptyNamespacePrefixRule::class)]
#[CoversClass(Psr4RootPathRule::class)]
final class Psr4ComposerFilePathCanonicalisationTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testEmptyNamespacePrefixRuleReportsCanonicalisedComposerFilePath(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "": "src/"
        }
    }
}
JSON);

        $violations = (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll(
            $basePath . '/.',
            Architecture::define()
        );

        $this->assertCount(1, $violations);
        $this->assertSame($this->expectedComposerFilePath($basePath), $violations[0]->file);
    }

    public function testRootPathRuleReportsCanonicalisedComposerFilePath(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "."
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll(
            $basePath . '/.',
            Architecture::define()
        );

        $this->assertCount(1, $violations);
        $this->assertSame($this->expectedComposerFilePath($basePath), $violations[0]->file);
    }

    private function expectedComposerFilePath(string $basePath): string
    {
        return str_replace('\\', '/', (string) realpath($basePath)) . '/composer.json';
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-psr4-composer-file-path');
        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }
}
