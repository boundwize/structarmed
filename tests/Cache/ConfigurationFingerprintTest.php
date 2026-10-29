<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\ConfigurationFingerprint;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Boundwize\StructArmed\Rule\Rules\Layer\MayNotDependOnRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Fixtures\Cache\RuleSettings;
use Boundwize\StructArmed\Tests\Fixtures\Cache\RuleSettingsGroup;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

use function fclose;
use function fopen;
use function strtolower;
use function strtoupper;

#[CoversClass(ConfigurationFingerprint::class)]
final class ConfigurationFingerprintTest extends TestCase
{
    public function testFreshPresetInstancesHaveStableIdentity(): void
    {
        $factory = new ConfigurationFingerprint();
        $build   = static fn(): Architecture => Architecture::define()->withPresets(Preset::PER(), Preset::YAGNI());

        $this->assertSame($factory->hash($build()), $factory->hash($build()));
    }

    public function testLocationAndBaselineAreNotEvaluationInputs(): void
    {
        $factory      = new ConfigurationFingerprint();
        $architecture = Architecture::define()->layer('Source', 'src/');
        $before       = $factory->hash($architecture);

        $architecture->cacheDirectory('different/cache')->baseline('different/baseline.json');

        $this->assertSame($before, $factory->hash($architecture));
    }

    public function testCustomRulesUseTheSameDeterministicIdentity(): void
    {
        $factory      = new ConfigurationFingerprint();
        $rule         = $this->createStub(RuleInterface::class);
        $architecture = Architecture::define()->rule('custom', $rule);

        $this->assertSame($factory->hash($architecture), $factory->hash(clone $architecture));
        $this->assertNotSame($factory->hash($architecture), $factory->hash((clone $architecture)->skipRule('custom')));
    }

    public function testRuleOptionsAndLayerPrecedenceContributeToIdentity(): void
    {
        $factory      = new ConfigurationFingerprint();
        $architecture = Architecture::define()->layer('One', 'src/')->layer('Two', 'src/');
        $before       = $factory->hash($architecture->rule('final', new MustBeFinalRule('One')));

        $this->assertNotSame($before, $factory->hash($architecture->replaceRule('final', new MustBeFinalRule('Two'))));
        $this->assertNotSame(
            $factory->hash(Architecture::define()->layer('One', 'src/')->layer('Two', 'src/')),
            $factory->hash(Architecture::define()->layer('Two', 'src/')->layer('One', 'src/'))
        );
    }

    public function testReadonlySettingsAndClosureCapturesContributeTheirValues(): void
    {
        $factory = new ConfigurationFingerprint();
        $this->assertSame(
            $factory->hash($this->withSettings(new RuleSettings(1))),
            $factory->hash($this->withSettings(new RuleSettings(1)))
        );
        $this->assertNotSame(
            $factory->hash($this->withSettings(new RuleSettings(1))),
            $factory->hash($this->withSettings(new RuleSettings(2)))
        );

        $callback = static fn(int $maximum): Closure => static fn(): int => $maximum;
        $this->assertNotSame(
            $factory->hash($this->withSettings($callback(1))),
            $factory->hash($this->withSettings($callback(2)))
        );
    }

    public function testOpaqueCollaboratorsAndResourcesHaveStableIdentities(): void
    {
        $factory          = new ConfigurationFingerprint();
        $one              = new stdClass();
        $two              = new stdClass();
        $one->runtimeMemo = 'one';
        $two->runtimeMemo = 'two';
        $first            = fopen('php://temp', 'w+');
        $second           = fopen('php://temp', 'w+');
        $this->assertIsResource($first);
        $this->assertIsResource($second);

        try {
            $this->assertSame(
                $factory->hash($this->withSettings([$one, $first])),
                $factory->hash($this->withSettings([$two, $second]))
            );
        } finally {
            fclose($first);
            fclose($second);
        }
    }

    public function testNativeInjectedAndMemoizedStateDoesNotChangeIdentity(): void
    {
        $factory      = new ConfigurationFingerprint();
        $deny         = new MayNotDependOnRule('One', 'Two');
        $psr4         = new Psr4NamespaceRule('Source');
        $architecture = Architecture::define()->rule('deny', $deny)->rule('psr4', $psr4);
        $before       = $factory->hash($architecture);

        $deny->injectClassNodeMap([
            'Example' => new ClassNode('Example', __FILE__, 1, 'Two', null, false, true, false, false),
        ]);
        $psr4->evaluateProject(__DIR__, $architecture);

        $this->assertSame($before, $factory->hash($architecture));
    }

    public function testFirstClassCallablesAndReadonlyBoundStateHaveDistinctIdentity(): void
    {
        $factory = new ConfigurationFingerprint();

        $this->assertNotSame(
            $factory->hash($this->withSettings(strtolower(...))),
            $factory->hash($this->withSettings(strtoupper(...)))
        );
        $this->assertNotSame(
            $factory->hash($this->withSettings((new RuleSettings(1))->callback())),
            $factory->hash($this->withSettings((new RuleSettings(2))->callback()))
        );
        $this->assertSame(
            $factory->hash($this->withSettings((new RuleSettings(1))->callback())),
            $factory->hash($this->withSettings((new RuleSettings(1))->callback()))
        );
    }

    public function testSharedAndDuplicatedReadonlyGraphsHaveTheSameIdentity(): void
    {
        $factory = new ConfigurationFingerprint();
        $shared  = new RuleSettingsGroup([new RuleSettings(1), new RuleSettings(2)]);
        $copy    = static fn(): RuleSettingsGroup => new RuleSettingsGroup([new RuleSettings(1), new RuleSettings(2)]);

        $this->assertSame(
            $factory->hash($this->withSettings([$shared, $shared, ['nested' => $shared]])),
            $factory->hash($this->withSettings([$copy(), $copy(), ['nested' => $copy()]]))
        );

        // Reusing a shallow digest at a deeper boundary must not change semantics.
        $deepShared = $shared;
        $deepCopy   = $copy();
        for ($depth = 0; $depth < 27; $depth++) {
            $deepShared = [$deepShared];
            $deepCopy   = [$deepCopy];
        }

        $this->assertSame(
            $factory->hash($this->withSettings([$shared, $deepShared])),
            $factory->hash($this->withSettings([$copy(), $deepCopy]))
        );
    }

    private function withSettings(mixed $settings): Architecture
    {
        return Architecture::define()->rule('custom', new class ($settings) implements RuleInterface {
            public function __construct(private readonly mixed $settings)
            {
            }

            public function appliesTo(ClassNode $classNode): bool
            {
                return $this->settings !== null;
            }

            public function evaluate(ClassNode $classNode): ?RuleViolation
            {
                return null;
            }
        });
    }
}
