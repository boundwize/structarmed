<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4EmptyNamespacePrefixRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4RootPathRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function realpath;
use function str_replace;

#[CoversClass(Psr4DirectoryExistsRule::class)]
#[CoversClass(Psr4EmptyNamespacePrefixRule::class)]
#[CoversClass(Psr4RootPathRule::class)]
#[CoversClass(Psr4SourcePathsRule::class)]
final class Psr4ComposerFilePathNormalisationTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    private const WINDOWS_STYLE_MISSING_BASE_PATH = 'C:\structarmed-missing-fixture\app';

    public function testDirectoryExistsRuleReportsForwardSlashesForWindowsStyleBasePath(): void
    {
        $violation = (new Psr4DirectoryExistsRule())->evaluateProject(
            self::WINDOWS_STYLE_MISSING_BASE_PATH,
            Architecture::define()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('C:/structarmed-missing-fixture/app/composer.json', $violation->file);
    }

    public function testSourcePathsRuleReportsForwardSlashesForWindowsStyleBasePath(): void
    {
        $violation = (new Psr4SourcePathsRule(null))->evaluateProject(
            self::WINDOWS_STYLE_MISSING_BASE_PATH,
            Architecture::define()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('C:/structarmed-missing-fixture/app/composer.json', $violation->file);
    }

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
