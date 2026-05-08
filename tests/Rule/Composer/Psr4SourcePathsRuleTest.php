<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

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

        $rule = new Psr4SourcePathsRule(['src', 'tests']);

        $this->assertNull($rule->evaluateProject($basePath, Architecture::define()));
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

        $rule = new Psr4SourcePathsRule(['src/', 'tests/']);

        $violation = $rule->evaluateProject($basePath, Architecture::define());

        $this->assertNotNull($violation);
        $this->assertStringContainsString('tests', $violation->message);
    }

    public function testFailsWhenComposerJsonIsMissing(): void
    {
        $rule = new Psr4SourcePathsRule(['src/']);

        $violation = $rule->evaluateProject($this->makeTempDir(), Architecture::define());

        $this->assertNotNull($violation);
        $this->assertStringContainsString('composer.json was not found', $violation->message);
    }

    public function testFailsWhenComposerJsonIsInvalid(): void
    {
        $rule = new Psr4SourcePathsRule(['src/']);

        $violation = $rule->evaluateProject(
            $this->makeTempProject('{not json'),
            Architecture::define()
        );

        $this->assertNotNull($violation);
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

        $rule = new Psr4SourcePathsRule(['src/', 'tests/']);

        $this->assertNull($rule->evaluateProject($basePath, Architecture::define()));
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

        $rule = new Psr4SourcePathsRule(['tests/']);

        $this->assertNull($rule->evaluateProject($basePath, Architecture::define()));
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

        $rule = new Psr4SourcePathsRule(null);

        $this->assertNull($rule->evaluateProject($basePath, Architecture::define()));
        $this->assertSame(['app', 'tests', 'specs'], $rule->sourcePathsFor($basePath));
    }

    private function makeTempProject(string $composerJson): string
    {
        $basePath = $this->makeTempDir();
        file_put_contents($basePath . '/composer.json', $composerJson);

        return $basePath;
    }

    private function makeTempDir(): string
    {
        $basePath = '/private/tmp/structarmed-psr4-rule-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        return $basePath;
    }
}
