<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4EmptyNamespacePrefixRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(Psr4EmptyNamespacePrefixRule::class)]
final class Psr4EmptyNamespacePrefixRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testPassesWhenAllNamespacePrefixesAreValid(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
JSON);

        $this->assertSame(
            [],
            (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testViolationForEmptyStringPrefix(): void
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

        $violations = (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('""', $violations[0]->message);
        $this->assertStringContainsString('autoload', $violations[0]->message);
    }

    public function testViolationForSingleBackslashPrefix(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "\\": "src/"
        }
    }
}
JSON);

        $violations = (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('autoload', $violations[0]->message);
    }

    public function testViolationInAutoloadDev(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload-dev": {
        "psr-4": {
            "": "tests/"
        }
    }
}
JSON);

        $violations = (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('autoload-dev', $violations[0]->message);
    }

    public function testReturnsAllViolationsAcrossBothSections(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "\\": "tests/"
        }
    }
}
JSON);

        $this->assertCount(
            2,
            (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testEvaluateProjectReturnsNullWhenNoViolations(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
JSON);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4EmptyNamespacePrefixRule())->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testEvaluateProjectReturnsViolationWhenEmptyPrefixFound(): void
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

        $this->assertInstanceOf(
            RuleViolation::class,
            (new Psr4EmptyNamespacePrefixRule())->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testSkipsWhenAutoloadSectionIsNotArray(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": "invalid"
}
JSON);

        $this->assertSame(
            [],
            (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testSkipsWhenPsr4SectionIsNotArray(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": "invalid"
    }
}
JSON);

        $this->assertSame(
            [],
            (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testSkipsNonStringNamespaceKeys(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": ["src/"]
    }
}
JSON);

        $this->assertSame(
            [],
            (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testPassesWhenComposerJsonIsMissing(): void
    {
        $this->assertSame(
            [],
            (new Psr4EmptyNamespacePrefixRule())->evaluateProjectAll($this->makeTempDir(), Architecture::define())
        );
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTempDir();
        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }

    private function makeTempDir(): string
    {
        return $this->makeTemporaryDirectory('structarmed-psr4-empty-namespace-prefix-rule');
    }
}
