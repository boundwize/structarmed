<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

#[CoversClass(Psr4DirectoryExistsRule::class)]
final class Psr4DirectoryExistsRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testPassesWhenAllPsr4DirectoriesExistOnDisk(): void
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

        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testFailsWhenPsr4DirectoryDoesNotExistOnDisk(): void
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

        $violation = (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('directory/not/exists', $violation->message);
        $this->assertStringContainsString('do not exist on disk', $violation->message);
    }

    public function testFailsWhenComposerJsonIsMissing(): void
    {
        $violation = (new Psr4DirectoryExistsRule())->evaluateProject($this->makeTempDir(), Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('composer.json was not found', $violation->message);
    }

    public function testFailsWhenComposerJsonIsInvalid(): void
    {
        $violation = (new Psr4DirectoryExistsRule())->evaluateProject(
            $this->makeTempProject('{not json'),
            Architecture::define()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('composer.json is not valid JSON', $violation->message);
    }

    public function testPassesWhenNoPsr4PathsAreDeclared(): void
    {
        $basePath = $this->makeTempProject('{}');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define())
        );
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
        return $this->makeTemporaryDirectory('structarmed-psr4-dir-exists-rule');
    }
}
