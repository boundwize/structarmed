<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Rule\Rules\File\Psr1PhpTagsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(PhpFileFinder::class)]
#[CoversClass(Psr1PhpTagsRule::class)]
final class Psr1PhpTagsRuleTest extends TestCase
{
    public function testViolatesShortOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<? echo "x";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesLongAndEchoTags(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\nclass Foo {}\n?>\n<?= 'x';");

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testIgnoresPhpTagsInsideStrings(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents(
                $basePath . '/src/Foo.php',
                "<?php\n\$template = \"<?php\\n\\nclass Generated {}\";\n"
            );

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testViolatesUpperCaseLongOpenTag(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<?PHP echo "x";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesWhenNoPhpFilesAreFound(): void
    {
        $basePath = $this->makeTempDir();

        try {
            $violations = (new Psr1PhpTagsRule(['missing/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertSame([], $violations);
        } finally {
            rmdir($basePath);
        }
    }

    public function testEvaluateProjectReturnsFirstViolation(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<? echo "x";');

            $violation = (new Psr1PhpTagsRule(['src/']))->evaluateProject($basePath, Architecture::define());

            $this->assertInstanceOf(RuleViolation::class, $violation);
            $this->assertStringContainsString('Foo.php', $violation->message);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testReturnsAllViolationsWhenMultipleFilesViolate(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', '<? echo "x";');
            file_put_contents($basePath . '/src/Bar.php', '<? echo "y";');

            $violations = (new Psr1PhpTagsRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

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
