<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Psr4DirectoryExistsRule::class)]
#[CoversClass(Psr4SourcePathsRule::class)]
final class Psr4ComposerFilePathNormalisationTest extends TestCase
{
    private const WINDOWS_STYLE_MISSING_BASE_PATH = 'C:\structarmed-missing-fixture\app';

    public function testDirectoryExistsRulePassesWhenComposerJsonIsMissingAtWindowsStyleBasePath(): void
    {
        $this->assertNull(
            (new Psr4DirectoryExistsRule())->evaluateProject(
                self::WINDOWS_STYLE_MISSING_BASE_PATH,
                Architecture::define()
            )
        );
    }

    public function testSourcePathsRulePassesWhenComposerJsonIsMissingAtWindowsStyleBasePath(): void
    {
        $this->assertNull(
            (new Psr4SourcePathsRule(null))->evaluateProject(
                self::WINDOWS_STYLE_MISSING_BASE_PATH,
                Architecture::define()
            )
        );
    }
}
