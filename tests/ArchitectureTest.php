<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Exception\RuleNotFoundException;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Architecture::class)]
final class ArchitectureTest extends TestCase
{
    public function testDefineReturnsNewInstance(): void
    {
        $this->assertInstanceOf(Architecture::class, Architecture::define());
    }

    public function testLayerRegistration(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/');

        $this->assertSame(
            ['Domain' => 'src/Domain/', 'Application' => 'src/Application/'],
            $architecture->getLayers()
        );
    }

    public function testLayerRegistrationAcceptsMultiplePaths(): void
    {
        $architecture = Architecture::define()
            ->layer('Source', ['src/', 'tests/']);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());
    }

    public function testSkipPathsAreRegistered(): void
    {
        $architecture = Architecture::define()
            ->skip([
                'tests/Fixtures/',
                'var/cache/*',
                'my.custom.rule' => ['storage/framework/'],
            ]);

        $this->assertSame(
            ['tests/Fixtures/', 'var/cache/*'],
            $architecture->getSkipPaths()
        );
        $this->assertSame(
            ['my.custom.rule' => ['storage/framework/']],
            $architecture->getRuleSkipPaths()
        );
    }

    public function testSkipPathAliasesUseUnifiedSkipConfiguration(): void
    {
        $architecture = Architecture::define()
            ->skipPath('tests/Fixtures/')
            ->skipPaths(['var/cache/', 'storage/framework/']);

        $this->assertSame(
            ['tests/Fixtures/', 'var/cache/', 'storage/framework/'],
            $architecture->getSkipPaths()
        );
        $this->assertSame([], $architecture->getRuleSkipPaths());
    }

    public function testSkipCanRegisterRuleBeforePresetAddsIt(): void
    {
        $architecture = Architecture::define()
            ->skip([
                'tests/Fixtures/',
                Psr1Preset::METHODS_MUST_BE_CAMEL_CASE,
            ])
            ->withPreset(Preset::PSR1(sourcePaths: ['src/']));

        $this->assertSame(['tests/Fixtures/'], $architecture->getSkipPaths());
        $this->assertSame([Psr1Preset::METHODS_MUST_BE_CAMEL_CASE], $architecture->getSkippedRuleKeys());
    }

    public function testSkipCanRegisterRuleAfterRuleExists(): void
    {
        $architecture = Architecture::define()
            ->rule('source.must_be_final', new MustBeFinalRule('Source'))
            ->skip(['source.must_be_final']);

        $this->assertSame([], $architecture->getSkipPaths());
        $this->assertSame(['source.must_be_final'], $architecture->getSkippedRuleKeys());
    }

    public function testCacheDirectoryIsRegistered(): void
    {
        $architecture = Architecture::define()
            ->cacheDirectory('var/cache/structarmed');

        $this->assertSame('var/cache/structarmed', $architecture->getCacheDirectory());
    }

    public function testRuleIsAdded(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $architecture    = Architecture::define()
            ->rule('my.custom.rule', $mustBeFinalRule);

        $this->assertArrayHasKey('my.custom.rule', $architecture->getRules());
    }

    public function testProjectRuleIsAdded(): void
    {
        $psr4SourcePathsRule = new Psr4SourcePathsRule(['src/']);
        $architecture        = Architecture::define()
            ->rule('my.project.rule', $psr4SourcePathsRule);

        $this->assertSame(['my.project.rule' => $psr4SourcePathsRule], $architecture->getRules());
    }

    public function testWithPresetAddsRules(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPreset(Preset::DDD());

        $this->assertArrayHasKey(DddPreset::ENTITY_MUST_BE_FINAL, $architecture->getRules());
        $this->assertArrayHasKey(DddPreset::DOMAIN_NO_DATETIME, $architecture->getRules());
    }

    public function testWithPresetsAddsAllRules(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPresets(Preset::DDD(), Preset::MVC());

        $this->assertArrayHasKey(DddPreset::ENTITY_MUST_BE_FINAL, $architecture->getRules());
    }

    public function testReplaceRuleReplacesExistingRule(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/');

        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPreset(Preset::DDD())
            ->replaceRule(DddPreset::ENTITY_MUST_BE_FINAL, $mustBeFinalRule);

        $rules = $architecture->getRules();
        $this->assertSame($mustBeFinalRule, $rules[DddPreset::ENTITY_MUST_BE_FINAL]);
    }

    public function testReplaceRuleThrowsIfKeyNotFound(): void
    {
        $this->expectException(RuleNotFoundException::class);

        Architecture::define()
            ->replaceRule('nonexistent.key', new MustBeFinalRule(layer: 'Domain'));
    }

    public function testWithoutRuleRemovesRule(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPreset(Preset::DDD())
            ->withoutRule(DddPreset::DOMAIN_NO_BASE_EXCEPTION);

        $this->assertArrayNotHasKey(DddPreset::DOMAIN_NO_BASE_EXCEPTION, $architecture->getRules());
    }

    public function testWithoutRuleThrowsIfKeyNotFound(): void
    {
        $this->expectException(RuleNotFoundException::class);

        Architecture::define()->withoutRule('nonexistent.key');
    }
}
