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
use function json_encode;

use const JSON_THROW_ON_ERROR;

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

    public function testPassesWhenComposerJsonIsMissing(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($this->makeTempDir(), Architecture::define())
        );
    }

    public function testPassesWhenComposerJsonIsInvalid(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject(
                $this->makeTempProject('{not json'),
                Architecture::define()
            )
        );
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
    }

    public function testPassesWhenDotRelativeSourcePathsExistInComposerPsr4Autoloads(): void
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

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['./src', '.\\tests/']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testPassesWhenAbsoluteSourcePathsExistInComposerPsr4Autoloads(): void
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

        $psr4SourcePathsRule = new Psr4SourcePathsRule([$basePath . '/src', $basePath . '/tests']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testPassesWhenComposerUsesAbsoluteSourcePath(): void
    {
        $basePath = $this->makeTempDir();
        file_put_contents(
            $basePath . '/composer.json',
            json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => $basePath . '/src/',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4SourcePathsRule->evaluateProject($basePath, Architecture::define())
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
        return $this->makeTemporaryDirectory('structarmed-psr4-rule');
    }
}
