<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\File;

use Boundwize\StructArmed\File\SkipPathMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function chdir;
use function getcwd;
use function mkdir;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(SkipPathMatcher::class)]
final class SkipPathMatcherTest extends TestCase
{
    public function testCompileReturnsSameInstanceForSameBasePathAndSkipPaths(): void
    {
        $this->assertSame(
            SkipPathMatcher::compile('/project', ['src/', 'vendor/']),
            SkipPathMatcher::compile('/project', ['src/', 'vendor/'])
        );
    }

    public function testCompileReturnsSameInstanceRegardlessOfSkipPathOrder(): void
    {
        $this->assertSame(
            SkipPathMatcher::compile('/project', ['src/', 'vendor/']),
            SkipPathMatcher::compile('/project', ['vendor/', 'src/'])
        );
    }

    public function testCompileReturnsSameInstanceRegardlessOfDuplicateSkipPaths(): void
    {
        $this->assertSame(
            SkipPathMatcher::compile('/project', ['src/', 'vendor/']),
            SkipPathMatcher::compile('/project', ['vendor/', 'src/', 'vendor/'])
        );
    }

    public function testCompileReturnsDifferentInstanceForDifferentSkipPaths(): void
    {
        $this->assertNotSame(
            SkipPathMatcher::compile('/project', ['src/']),
            SkipPathMatcher::compile('/project', ['vendor/'])
        );
    }

    public function testCompileReturnsDifferentInstanceForDifferentBasePath(): void
    {
        $this->assertNotSame(
            SkipPathMatcher::compile('/project-a', ['src/']),
            SkipPathMatcher::compile('/project-b', ['src/'])
        );
    }

    public function testLeadingSlashSkipPathMatchesOnlyTheAbsoluteLocation(): void
    {
        $skipPathMatcher = SkipPathMatcher::compile('/project', ['/vendor']);

        $this->assertTrue($skipPathMatcher->isSkipped('/vendor/autoload.php'));
        $this->assertFalse($skipPathMatcher->isSkipped('/project/vendor/autoload.php'));
        $this->assertFalse($skipPathMatcher->isSkipped('/project/lib/Bar.php'));
    }

    public function testReorderedSkipPathsMatchIdentically(): void
    {
        $skipPathMatcher = SkipPathMatcher::compile('/project', ['vendor/', 'src/', 'src/']);

        $this->assertTrue($skipPathMatcher->isSkipped('/project/src/Foo.php'));
        $this->assertTrue($skipPathMatcher->isSkipped('/project/vendor/autoload.php'));
        $this->assertFalse($skipPathMatcher->isSkipped('/project/lib/Bar.php'));
    }

    /**
     * A relative, non-glob skip path also matches its location resolved from the
     * current working directory, even when that differs from the base path.
     */
    public function testRelativeSkipPathMatchesLocationResolvedFromCurrentWorkingDirectory(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/structarmed-skip-' . uniqid();
        mkdir($workingDirectory . '/skipme', 0777, true);

        $originalWorkingDirectory = getcwd();
        $this->assertNotFalse($originalWorkingDirectory);
        chdir($workingDirectory);

        try {
            $canonicalWorkingDirectory = realpath($workingDirectory);
            $this->assertNotFalse($canonicalWorkingDirectory);

            $skipPathMatcher = SkipPathMatcher::compile('/project', ['skipme']);

            // resolved from the current working directory
            $this->assertTrue($skipPathMatcher->isSkipped($canonicalWorkingDirectory . '/skipme/Foo.php'));
            // resolved against the base path
            $this->assertTrue($skipPathMatcher->isSkipped('/project/skipme/Foo.php'));
            $this->assertFalse($skipPathMatcher->isSkipped($canonicalWorkingDirectory . '/other/Foo.php'));
            $this->assertFalse($skipPathMatcher->isSkipped('/project/other/Foo.php'));
        } finally {
            chdir($originalWorkingDirectory);
            rmdir($workingDirectory . '/skipme');
            rmdir($workingDirectory);
        }
    }
}
