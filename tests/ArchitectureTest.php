<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Exception\RuleNotFoundException;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
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
        $arch = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/');

        $this->assertSame(
            ['Domain' => 'src/Domain/', 'Application' => 'src/Application/'],
            $arch->getLayers()
        );
    }

    public function testLayerRegistrationAcceptsMultiplePaths(): void
    {
        $arch = Architecture::define()
            ->layer('Source', ['src/', 'tests/']);

        $this->assertSame(['Source' => ['src/', 'tests/']], $arch->getLayers());
    }

    public function testRuleIsAdded(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain');
        $arch = Architecture::define()
            ->rule('my.custom.rule', $rule);

        $this->assertArrayHasKey('my.custom.rule', $arch->getRules());
    }

    public function testWithPresetAddsRules(): void
    {
        $arch = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPreset(Preset::DDD());

        $this->assertArrayHasKey(DddPreset::ENTITY_MUST_BE_FINAL, $arch->getRules());
        $this->assertArrayHasKey(DddPreset::DOMAIN_NO_DATETIME, $arch->getRules());
    }

    public function testWithPresetsAddsAllRules(): void
    {
        $arch = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPresets(Preset::DDD(), Preset::MVC());

        $this->assertArrayHasKey(DddPreset::ENTITY_MUST_BE_FINAL, $arch->getRules());
    }

    public function testReplaceRuleReplacesExistingRule(): void
    {
        $originalRule    = new MustBeFinalRule(layer: 'Domain');
        $replacementRule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/');

        $arch = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPreset(Preset::DDD())
            ->replaceRule(DddPreset::ENTITY_MUST_BE_FINAL, $replacementRule);

        $rules = $arch->getRules();
        $this->assertSame($replacementRule, $rules[DddPreset::ENTITY_MUST_BE_FINAL]);
    }

    public function testReplaceRuleThrowsIfKeyNotFound(): void
    {
        $this->expectException(RuleNotFoundException::class);

        Architecture::define()
            ->replaceRule('nonexistent.key', new MustBeFinalRule(layer: 'Domain'));
    }

    public function testWithoutRuleRemovesRule(): void
    {
        $arch = Architecture::define()
            ->layer('Domain', 'src/Domain/')
            ->layer('Application', 'src/Application/')
            ->layer('Infrastructure', 'src/Infrastructure/')
            ->withPreset(Preset::DDD())
            ->withoutRule(DddPreset::DOMAIN_NO_BASE_EXCEPTION);

        $this->assertArrayNotHasKey(DddPreset::DOMAIN_NO_BASE_EXCEPTION, $arch->getRules());
    }

    public function testWithoutRuleThrowsIfKeyNotFound(): void
    {
        $this->expectException(RuleNotFoundException::class);

        Architecture::define()->withoutRule('nonexistent.key');
    }
}
