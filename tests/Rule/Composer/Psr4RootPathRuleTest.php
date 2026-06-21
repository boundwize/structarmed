<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4RootPathRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(Psr4RootPathRule::class)]
final class Psr4RootPathRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testPassesWhenNoPsr4PathMapsToRoot(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/",
            "UnixRoot\\": "/",
            "WindowsRoot\\": "\\\\"
        }
    }
}
JSON);

        $this->assertSame(
            [],
            (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testViolationWhenEmptyNamespaceMapsToRoot(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "": "./"
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('""', $violations[0]->message);
        $this->assertStringContainsString('"./"', $violations[0]->message);
        $this->assertStringContainsString('autoload', $violations[0]->message);
    }

    public function testViolationWhenNamedNamespaceMapsToRoot(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Root\\": "."
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"Root\\"', $violations[0]->message);
        $this->assertStringContainsString('"."', $violations[0]->message);
        $this->assertStringContainsString('autoload', $violations[0]->message);
    }

    public function testViolationWhenAutoloadDevMapsToRoot(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload-dev": {
        "psr-4": {
            "": "./"
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('autoload-dev', $violations[0]->message);
    }

    public function testReturnsAllViolationsAcrossBothSections(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "": "./"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Root\\": "."
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(2, $violations);
    }

    public function testViolationWhenNamespaceMapsToEmptyStringPath(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Symfony\\Component\\Form\\": ""
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"Symfony\\Component\\Form\\"', $violations[0]->message);
        $this->assertStringContainsString('""', $violations[0]->message);
        $this->assertStringContainsString('autoload', $violations[0]->message);
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
            (new Psr4RootPathRule())->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testEvaluateProjectReturnsViolationWhenRootPathFound(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "": "./"
        }
    }
}
JSON);

        $this->assertInstanceOf(
            RuleViolation::class,
            (new Psr4RootPathRule())->evaluateProject($basePath, Architecture::define())
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
            (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define())
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
            (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testSkipsNonStringNamespaceKeys(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": ["./"]
    }
}
JSON);

        $this->assertSame(
            [],
            (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testSkipsNonStringPathValues(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Root\\": [false, "."]
        }
    }
}
JSON);

        $violations = (new Psr4RootPathRule())->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
    }

    public function testPassesWhenComposerJsonIsInvalid(): void
    {
        $this->assertSame(
            [],
            (new Psr4RootPathRule())->evaluateProjectAll($this->makeTempProject('{not json'), Architecture::define())
        );
    }

    public function testPassesWhenComposerJsonIsMissing(): void
    {
        $this->assertSame(
            [],
            (new Psr4RootPathRule())->evaluateProjectAll($this->makeTempDir(), Architecture::define())
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
        return $this->makeTemporaryDirectory('structarmed-psr4-root-path-rule');
    }
}
