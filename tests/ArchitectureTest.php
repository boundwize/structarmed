<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests;

use App\Foo;
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
            ->skip(['tests/Fixtures/'])
            ->skipRule(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE)
            ->withPreset(Preset::PSR1(sourcePaths: ['src/']));

        $this->assertSame(['tests/Fixtures/'], $architecture->getSkipPaths());
        $this->assertSame([Psr1Preset::METHODS_MUST_BE_CAMEL_CASE], $architecture->getSkippedRuleKeys());
    }

    public function testSkipCanRegisterRuleAfterRuleExists(): void
    {
        $architecture = Architecture::define()
            ->rule('source.must_be_final', new MustBeFinalRule('Source'))
            ->skipRules(['source.must_be_final']);

        $this->assertSame([], $architecture->getSkipPaths());
        $this->assertSame(['source.must_be_final'], $architecture->getSkippedRuleKeys());
    }

    public function testCacheDirectoryIsRegistered(): void
    {
        $architecture = Architecture::define()
            ->cacheDirectory('var/cache/structarmed');

        $this->assertSame('var/cache/structarmed', $architecture->getCacheDirectory());
    }

    public function testCacheDirectoryCanBeNull(): void
    {
        $architecture = Architecture::define()
            ->cacheDirectory(null);

        $this->assertNull($architecture->getCacheDirectory());
    }

    public function testBaselineIsRegistered(): void
    {
        $architecture = Architecture::define()
            ->baseline('structarmed-baseline.php');

        $this->assertSame('structarmed-baseline.php', $architecture->getBaseline());
    }

    public function testBaselineCanBeNull(): void
    {
        $architecture = Architecture::define()
            ->baseline(null);

        $this->assertNull($architecture->getBaseline());
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

    public function testLayerPatternIsRegistered(): void
    {
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/');

        $patterns = $architecture->getLayerPatterns();

        $this->assertArrayHasKey('HTTP', $patterns);
        $this->assertSame('/^App\\\\HTTP\\\\.*$/', $patterns['HTTP']['pattern']);
        $this->assertNull($patterns['HTTP']['excludePattern']);
    }

    public function testLayerPatternAcceptsMultiplePatterns(): void
    {
        $architecture = Architecture::define()
            ->layerPattern('Service', [
                '/^App\\\\Service\\\\.*$/',
                '/^App\\\\Application\\\\.*Service$/',
            ]);

        $patterns = $architecture->getLayerPatterns();

        $this->assertSame([
            '/^App\\\\Service\\\\.*$/',
            '/^App\\\\Application\\\\.*Service$/',
        ], $patterns['Service']['pattern']);
        $this->assertNull($patterns['Service']['excludePattern']);
    }

    public function testLayerPatternWithExcludePatternIsRegistered(): void
    {
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/', '/(Exception|URI)/');

        $patterns = $architecture->getLayerPatterns();

        $this->assertSame('/^App\\\\HTTP\\\\.*$/', $patterns['HTTP']['pattern']);
        $this->assertSame('/(Exception|URI)/', $patterns['HTTP']['excludePattern']);
    }

    public function testLayerPatternAcceptsMultipleExcludePatterns(): void
    {
        $architecture = Architecture::define()
            ->layerPattern('HTTP', '/^App\\\\HTTP\\\\.*$/', [
                '/Exception$/',
                '/^App\\\\HTTP\\\\URI$/',
            ]);

        $patterns = $architecture->getLayerPatterns();

        $this->assertSame('/^App\\\\HTTP\\\\.*$/', $patterns['HTTP']['pattern']);
        $this->assertSame([
            '/Exception$/',
            '/^App\\\\HTTP\\\\URI$/',
        ], $patterns['HTTP']['excludePattern']);
    }

    public function testRulesetIsRegistered(): void
    {
        $ruleset = [
            'Domain'         => ['Application'],
            'Application'    => ['Domain', 'Infrastructure'],
            'Infrastructure' => [],
        ];

        $architecture = Architecture::define()->ruleset($ruleset);

        $this->assertSame($ruleset, $architecture->getRuleset());
    }

    public function testSkipClassViolationIsSingleDependency(): void
    {
        $architecture = Architecture::define()
            ->skipClassViolation(Foo::class, 'App\\Bar');

        $this->assertSame(
            [Foo::class => ['App\\Bar']],
            $architecture->getClassViolationSkips()
        );
    }

    public function testSkipClassViolationIsMultipleDependencies(): void
    {
        $architecture = Architecture::define()
            ->skipClassViolation(Foo::class, ['App\\Bar', 'App\\Baz']);

        $this->assertSame(
            [Foo::class => ['App\\Bar', 'App\\Baz']],
            $architecture->getClassViolationSkips()
        );
    }

    public function testSkipClassViolationAccumulatesAcrossCalls(): void
    {
        $architecture = Architecture::define()
            ->skipClassViolation(Foo::class, 'App\\Bar')
            ->skipClassViolation(Foo::class, 'App\\Baz')
            ->skipClassViolation('App\\Qux', 'App\\Bar');

        $skips = $architecture->getClassViolationSkips();

        $this->assertSame(['App\\Bar', 'App\\Baz'], $skips[Foo::class]);
        $this->assertSame(['App\\Bar'], $skips['App\\Qux']);
    }

    public function testSkipPathsForRulesetAcceptsStringAndArray(): void
    {
        $architecture = Architecture::define()
            ->skipPathsForRuleset('tests/')
            ->skipPathsForRuleset(['fixtures/', 'stubs/']);

        $this->assertSame(['tests/', 'fixtures/', 'stubs/'], $architecture->getRulesetSkipPaths());
    }
}
