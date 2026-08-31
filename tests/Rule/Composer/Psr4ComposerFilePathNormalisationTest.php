<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Composer;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Psr4DirectoryExistsRule::class)]
#[CoversClass(Psr4SourcePathsRule::class)]
final class Psr4ComposerFilePathNormalisationTest extends TestCase
{
    private const WINDOWS_STYLE_MISSING_BASE_PATH = 'C:\structarmed-missing-fixture\app';

    public function testDirectoryExistsRuleReportsForwardSlashesForWindowsStyleBasePath(): void
    {
        $violation = (new Psr4DirectoryExistsRule())->evaluateProject(
            self::WINDOWS_STYLE_MISSING_BASE_PATH,
            Architecture::define()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('C:/structarmed-missing-fixture/app/composer.json', $violation->file);
    }

    public function testSourcePathsRuleReportsForwardSlashesForWindowsStyleBasePath(): void
    {
        $violation = (new Psr4SourcePathsRule(null))->evaluateProject(
            self::WINDOWS_STYLE_MISSING_BASE_PATH,
            Architecture::define()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('C:/structarmed-missing-fixture/app/composer.json', $violation->file);
    }
}
