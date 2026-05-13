<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(Psr4SourcePathsRule::class)]
final class Psr4SourcePathsRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testPassesWhenSourcePathsExistInComposerPsr4Autoloads(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    }
}
JSON);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src', 'tests']);

        $this->assertSame(
            [],
            $psr4SourcePathsRule->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testFailsWhenSourcePathIsMissingFromComposerPsr4Autoloads(): void
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

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/', 'tests/']);

        $violations = $psr4SourcePathsRule->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('tests', $violations[0]->message);
    }

    public function testFailsWhenComposerJsonIsMissing(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $violations = $psr4SourcePathsRule->evaluateProjectAll($this->makeTempDir(), Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('composer.json was not found', $violations[0]->message);
    }

    public function testFailsWhenComposerJsonIsInvalid(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $violations = $psr4SourcePathsRule->evaluateProjectAll(
            $this->makeTempProject('{not json'),
            Architecture::define()
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('composer.json is not valid JSON', $violations[0]->message);
    }

    public function testPassesWhenComposerPsr4MappingUsesPathList(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": "invalid shape",
    "autoload-dev": {
        "psr-4": {
            "App\\": ["src/", "tests/"],
            "Broken\\": [false]
        }
    }
}
JSON);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/', 'tests/']);

        $this->assertSame(
            [],
            $psr4SourcePathsRule->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testSkipsComposerPsr4SectionWithInvalidShape(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": "invalid shape"
    },
    "autoload-dev": {
        "psr-4": {
            "App\\": "tests/"
        }
    }
}
JSON);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['tests/']);

        $this->assertSame(
            [],
            $psr4SourcePathsRule->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testReadsSourcePathsFromComposerWhenSourcePathsAreNotConfigured(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": ["tests/", "specs/"]
        }
    }
}
JSON);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(null);

        $this->assertSame(
            [],
            $psr4SourcePathsRule->evaluateProjectAll($basePath, Architecture::define())
        );
        $this->assertSame(['app', 'tests', 'specs'], $psr4SourcePathsRule->sourcePathsFor($basePath));
    }

    public function testNormalisesExplicitSourcePaths(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule([' src/ ', 'tests\\']);

        $this->assertSame(['src', 'tests'], $psr4SourcePathsRule->sourcePathsFor($this->makeTempDir()));
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTempDir();
        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }

    private function makeTempDir(): string
    {
        return $this->makeTemporaryDirectory('structarmed-psr4-rule');
    }
}
