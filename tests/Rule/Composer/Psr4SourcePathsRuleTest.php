<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

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
JSON, ['src', 'tests']);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src', 'tests']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
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
JSON, ['src']);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/', 'tests/']);

        $violation = $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('tests', $violation->message);
    }

    public function testFailsWhenComposerJsonIsMissing(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $violation = $psr4SourcePathsRule->evaluateProject($this->makeTempDir(), Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('composer.json was not found', $violation->message);
    }

    public function testFailsWhenComposerJsonIsInvalid(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $violation = $psr4SourcePathsRule->evaluateProject(
            $this->makeTempProject('{not json'),
            Architecture::define()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('composer.json is not valid JSON', $violation->message);
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
JSON, ['src', 'tests']);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/', 'tests/']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
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
JSON, ['tests']);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['tests/']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
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
JSON, ['app', 'tests', 'specs']);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(null);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
        );
        $this->assertSame(['app', 'tests', 'specs'], $psr4SourcePathsRule->sourcePathsFor($basePath));
    }

    public function testFailsWhenPsr4PathDirectoryDoesNotExistOnDisk(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "View\\": "directory/not/exists"
        }
    }
}
JSON);

        $violation = (new Psr4SourcePathsRule(null))->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('directory/not/exists', $violation->message);
        $this->assertStringContainsString('do not exist on disk', $violation->message);
    }

    public function testNormalisesExplicitSourcePaths(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule([' src/ ', 'tests\\']);

        $this->assertSame(['src', 'tests'], $psr4SourcePathsRule->sourcePathsFor($this->makeTempDir()));
    }

    /**
     * @param list<string> $dirs
     */
    private function makeTempProject(string $composerJson, array $dirs = []): string
    {
        $basePath = $this->makeTempDir();
        file_put_contents($basePath . '/composer.json', $composerJson);

        foreach ($dirs as $dir) {
            mkdir($basePath . '/' . $dir, 0777, true);
        }

        return $basePath;
    }

    private function makeTempDir(): string
    {
        return $this->makeTemporaryDirectory('structarmed-psr4-rule');
    }
}
