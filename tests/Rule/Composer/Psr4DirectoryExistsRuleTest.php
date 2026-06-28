<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\AbstractJsonRecastFixableRule;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\Composer\RemoveMissingPsr4PathVisitor;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\JsonRecastFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function mkdir;

#[CoversClass(Psr4DirectoryExistsRule::class)]
#[CoversClass(AbstractJsonRecastFixableRule::class)]
#[CoversClass(JsonRecastFixerProcessor::class)]
#[CoversClass(RemoveMissingPsr4PathVisitor::class)]
final class Psr4DirectoryExistsRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new Psr4DirectoryExistsRule());
    }

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

    public function testFixRemovesPsr4MappingsForMissingDirectories(): void
    {
        $basePath                = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/",
            "Missing\\": "missing/",
            "Mixed\\": ["src/", "missing-tests/"],
            "Gone\\": ["missing-one/", "missing-two/"]
        }
    },
    "autoload-dev": {
        "psr-4": {
            "ExistingTests\\": "tests/",
            "MissingTests\\": "missing-tests/"
        }
    }
}
JSON, ['src', 'tests']);
        $psr4DirectoryExistsRule = new Psr4DirectoryExistsRule();
        $violation               = $psr4DirectoryExistsRule->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertTrue($psr4DirectoryExistsRule->fix($violation));

        $this->assertSame(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/",
            "Mixed\\": ["src/"]
        }
    },
    "autoload-dev": {
        "psr-4": {
            "ExistingTests\\": "tests/"
        }
    }
}
JSON, file_get_contents($basePath . '/composer.json'));
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4DirectoryExistsRule->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testFixRemovesPsr4BlockWhenEveryMappingDirectoryIsMissing(): void
    {
        $basePath                = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "View\\": "directory/not/exists"
        }
    }
}
JSON);
        $psr4DirectoryExistsRule = new Psr4DirectoryExistsRule();
        $violation               = $psr4DirectoryExistsRule->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertTrue($psr4DirectoryExistsRule->fix($violation));

        $this->assertSame(<<<'JSON'
{}
JSON, file_get_contents($basePath . '/composer.json'));
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $psr4DirectoryExistsRule->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testFixReturnsFalseWhenAllPsr4DirectoriesExist(): void
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

        $this->assertFalse((new Psr4DirectoryExistsRule())->fix(new RuleViolation(
            message:   'PSR-4 source path(s) [src] declared in composer.json do not exist on disk',
            file:      $basePath . '/composer.json',
            line:      1,
            className: '',
        )));
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
