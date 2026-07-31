<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\File;

use Boundwize\StructArmed\File\SkipPathMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
}
