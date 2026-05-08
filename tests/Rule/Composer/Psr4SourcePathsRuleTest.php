<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(Psr4SourcePathsRule::class)]
final class Psr4SourcePathsRuleTest extends TestCase
{
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
JSON);

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
JSON);

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
JSON);

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
JSON);

        $psr4SourcePathsRule = new Psr4SourcePathsRule(null);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
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
        $basePath = sys_get_temp_dir() . '/structarmed-psr4-rule-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }
}
