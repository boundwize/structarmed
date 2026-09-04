<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\AbstractJsonRecastFixableRule;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\JsonRecastFixerProcessor;
use Boundwize\StructArmed\Rule\Fixer\JsonRecast\ObjectItemNode\RemoveMissingPsr4PathVisitor;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function sprintf;

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

    public function testPassesWhenPsr4PathIsAbsoluteAndExistsOnDisk(): void
    {
        $absolutePath = $this->makeTempDir();
        $basePath     = $this->makeTempProject(sprintf(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": %s
        }
    }
}
JSON, json_encode($absolutePath)));

        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testPassesWhenPsr4PathHasDotSlashPrefixAndExistsOnDisk(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "./src/"
        }
    }
}
JSON, ['src']);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testFailsWhenPsr4PathHasDotSlashPrefixAndDoesNotExistOnDisk(): void
    {
        $basePath = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "View\\": "./directory/not/exists"
        }
    }
}
JSON);

        $violation = (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('directory/not/exists', $violation->message);
        $this->assertStringContainsString('do not exist on disk', $violation->message);
    }

    public function testFailsWhenPsr4PathIsAbsoluteAndDoesNotExistOnDisk(): void
    {
        $absolutePath = $this->makeTempDir() . '/directory/not/exists';
        $basePath     = $this->makeTempProject(sprintf(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": %s
        }
    }
}
JSON, json_encode($absolutePath)));

        $violation = (new Psr4DirectoryExistsRule())->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString(Path::normalise($absolutePath), $violation->message);
        $this->assertStringContainsString('do not exist on disk', $violation->message);
    }

    public function testPassesWhenComposerJsonIsMissing(): void
    {
        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4DirectoryExistsRule())->evaluateProject($this->makeTempDir(), Architecture::define())
        );
    }

    public function testPassesWhenComposerJsonIsInvalid(): void
    {
        $this->assertNotInstanceOf(
            RuleViolation::class,
            (new Psr4DirectoryExistsRule())->evaluateProject(
                $this->makeTempProject('{not json'),
                Architecture::define()
            )
        );
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

        $batchBasePath  = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Missing\\": "missing/"
        }
    }
}
JSON);
        $batchViolation = $psr4DirectoryExistsRule->evaluateProject($batchBasePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $batchViolation);
        $this->assertTrue($psr4DirectoryExistsRule->fix($batchViolation, $batchViolation));
        $this->assertSame("{\n}", file_get_contents($batchBasePath . '/composer.json'));

        $firstBasePath  = $this->makeTempProject('{}');
        $secondBasePath = $this->makeTempProject('{}');

        $this->assertFalse($psr4DirectoryExistsRule->fix(
            new RuleViolation(
                message:   'First violation',
                file:      $firstBasePath . '/composer.json',
                line:      1,
                className: '',
            ),
            new RuleViolation(
                message:   'Second violation',
                file:      $secondBasePath . '/composer.json',
                line:      1,
                className: '',
            ),
        ));
        $this->assertSame('{}', file_get_contents($firstBasePath . '/composer.json'));
        $this->assertSame('{}', file_get_contents($secondBasePath . '/composer.json'));
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
{
}
JSON, file_get_contents($basePath . '/composer.json'));
    }

    public function testFixKeepsUnchangedEmptyPsr4Block(): void
    {
        $basePath                = $this->makeTempProject(<<<'JSON'
{
    "autoload": {
        "psr-4": {
        }
    },
    "autoload-dev": {
        "psr-4": {
            "View\\Tests\\": "directory/not/exists"
        }
    }
}
JSON);
        $psr4DirectoryExistsRule = new Psr4DirectoryExistsRule();
        $violation               = $psr4DirectoryExistsRule->evaluateProject($basePath, Architecture::define());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertTrue($psr4DirectoryExistsRule->fix($violation));

        $this->assertSame(<<<'JSON'
{
    "autoload": {
        "psr-4": {
        }
    }
}
JSON, file_get_contents($basePath . '/composer.json'));
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
