<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Rule\Rules\File\Psr1Utf8WithoutBomRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(PhpFileFinder::class)]
#[CoversClass(Psr1Utf8WithoutBomRule::class)]
final class Psr1Utf8WithoutBomRuleTest extends TestCase
{
    public function testViolatesUtf8Bom(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "\xEF\xBB\xBF<?php class Foo {}");

            $violations = (new Psr1Utf8WithoutBomRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesInvalidUtf8WithoutBom(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\n// invalid: \xC3\x28\n");

            $violations = (new Psr1Utf8WithoutBomRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesFilesWithoutBomAndMissingPaths(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nclass Foo {}\n");

            $this->assertSame(
                [],
                (new Psr1Utf8WithoutBomRule(['src/']))->evaluateProjectAll($basePath, Architecture::define())
            );
            $this->assertSame(
                [],
                (new Psr1Utf8WithoutBomRule(['missing/']))->evaluateProjectAll($basePath, Architecture::define())
            );
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testEvaluateProjectReturnsFirstViolation(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "\xEF\xBB\xBF<?php class Foo {}");

            $violation = (new Psr1Utf8WithoutBomRule(['src/']))->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertStringContainsString('Foo.php', $violation->message);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new Psr1Utf8WithoutBomRule(['src/']));
    }

    public function testFixesUtf8Bom(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "\xEF\xBB\xBF<?php class Foo {}");

            $psr1Utf8WithoutBomRule = new Psr1Utf8WithoutBomRule(['src/']);
            $violation              = $psr1Utf8WithoutBomRule->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertTrue($psr1Utf8WithoutBomRule->fix($violation));
            $this->assertSame('<?php class Foo {}', file_get_contents($basePath . '/src/Foo.php'));
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testDoesNotFixFileWithoutBom(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?php class Foo {}');

            $psr1Utf8WithoutBomRule = new Psr1Utf8WithoutBomRule(['src/']);

            $this->assertFalse($psr1Utf8WithoutBomRule->fix(new RuleViolation(
                message:   'File must use UTF-8 without BOM',
                file:      $basePath . '/src/Foo.php',
                line:      1,
                className: '',
            )));
            $this->assertSame('<?php class Foo {}', file_get_contents($basePath . '/src/Foo.php'));
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testDoesNotFixMissingFile(): void
    {
        $this->assertFalse((new Psr1Utf8WithoutBomRule(['src/']))->fix(new RuleViolation(
            message:   'File must use UTF-8 without BOM',
            file:      sys_get_temp_dir() . '/structarmed-missing-file-' . bin2hex(random_bytes(6)) . '.php',
            line:      1,
            className: '',
        )));
    }

    public function testReturnsAllViolationsWhenMultipleFilesViolate(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "\xEF\xBB\xBF<?php class Foo {}");
            file_put_contents($basePath . '/src/Bar.php', "\xEF\xBB\xBF<?php class Bar {}");

            $violations = (new Psr1Utf8WithoutBomRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(2, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            unlink($basePath . '/src/Bar.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/structarmed-psr1-' . bin2hex(random_bytes(6));
        mkdir($path);

        return $path;
    }
}
