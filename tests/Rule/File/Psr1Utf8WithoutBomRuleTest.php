<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Rule\Rules\File\Psr1Utf8WithoutBomRule;
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

    public function testViolatesInvalidUtf8(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/Foo.php', "<?php\n// invalid: \xC3\x28\n");

            $violations = (new Psr1Utf8WithoutBomRule(['src/']))->evaluateProjectAll($basePath, Architecture::define());

            $this->assertCount(1, $violations);
            $this->assertStringContainsString('valid UTF-8', $violations[0]->message);
        } finally {
            unlink($basePath . '/src/Foo.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testPassesValidUtf8AndMissingPaths(): void
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

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/structarmed-psr1-' . bin2hex(random_bytes(6));
        mkdir($path);

        return $path;
    }
}
