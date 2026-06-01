<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Preset;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Preset\Presets\MvcPreset;
use Boundwize\StructArmed\Preset\Presets\Psr12Preset;
use Boundwize\StructArmed\Preset\Presets\Psr15Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Preset\Presets\ResolvesSourceLayerNameTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Preset::class)]
#[CoversClass(DddPreset::class)]
#[CoversClass(MvcPreset::class)]
#[CoversClass(Psr1Preset::class)]
#[CoversClass(Psr12Preset::class)]
#[CoversClass(Psr15Preset::class)]
#[CoversClass(Psr4Preset::class)]
#[CoversClass(ResolvesSourceLayerNameTrait::class)]
final class PresetTest extends TestCase
{
    public function testPsr1PresetRegistersSourceLayerAndRules(): void
    {
        $architecture = Architecture::define();

        Preset::PSR1(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_UTF8_WITHOUT_BOM, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS, $rules);
        $this->assertArrayHasKey(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASSES_MUST_BE_STUDLY_CAPS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASS_CONSTANTS_MUST_BE_UPPER_CASE, $rules);
        $this->assertArrayHasKey(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE, $rules);
    }

    public function testPsr12PresetAppliesPsr1RulesAndAddsVisibilityRules(): void
    {
        $architecture = Architecture::define();

        Preset::PSR12(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_UTF8_WITHOUT_BOM, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS, $rules);
        $this->assertArrayHasKey(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASSES_MUST_BE_STUDLY_CAPS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASS_CONSTANTS_MUST_BE_UPPER_CASE, $rules);
        $this->assertArrayHasKey(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE, $rules);
        $this->assertArrayHasKey(Psr12Preset::METHODS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::CONSTANTS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::PROPERTIES_MUST_DECLARE_VISIBILITY, $rules);
    }

    public function testPsr4PresetRegistersSourceLayerAndRules(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());
        $this->assertArrayHasKey(
            Psr4Preset::CLASSES_MUST_MATCH_COMPOSER,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK,
            $architecture->getRules()
        );
    }

    public function testPsr4PresetUsesComposerSourcePathsByDefault(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4()->apply($architecture);

        $this->assertSame(['Source' => []], $architecture->getLayers());
        $this->assertArrayHasKey(
            Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK,
            $architecture->getRules()
        );
    }

    public function testPsr15PresetRegistersSourceLayerAndRules(): void
    {
        $architecture = Architecture::define();

        Preset::PSR15(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());
        $this->assertCount(4, $architecture->getRules());
        $this->assertArrayHasKey(
            Psr15Preset::MIDDLEWARE_MUST_IMPLEMENT_MIDDLEWARE_INTERFACE,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            Psr15Preset::HANDLER_MUST_IMPLEMENT_REQUEST_HANDLER_INTERFACE,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            Psr15Preset::MIDDLEWARE_INTERFACE_IMPLEMENTATION_MUST_HAVE_MIDDLEWARE_SUFFIX,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            Psr15Preset::REQUEST_HANDLER_INTERFACE_IMPLEMENTATION_MUST_HAVE_HANDLER_SUFFIX,
            $architecture->getRules()
        );
    }

    public function testPsr15PresetUsesComposerSourcePathsByDefault(): void
    {
        $architecture = Architecture::define();

        Preset::PSR15()->apply($architecture);

        $this->assertSame(['Source' => []], $architecture->getLayers());
    }

    public function testDddPresetRegistersAllDefaultRules(): void
    {
        $architecture = Architecture::define();

        Preset::DDD()->apply($architecture);

        $this->assertSame(
            [
                'Domain'         => 'src/Domain/',
                'Application'    => 'src/Application/',
                'Infrastructure' => 'src/Infrastructure/',
            ],
            $architecture->getLayers()
        );

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(DddPreset::DOMAIN_NOT_DEPEND_APPLICATION, $rules);
        $this->assertArrayHasKey(DddPreset::ENTITY_MUST_BE_FINAL, $rules);
        $this->assertArrayHasKey(DddPreset::VALUE_OBJECT_MUST_BE_FINAL, $rules);
        $this->assertArrayHasKey(DddPreset::EVENT_MUST_BE_FINAL, $rules);
        $this->assertArrayHasKey(DddPreset::DOMAIN_NO_JSON_SERIALIZABLE, $rules);
        $this->assertArrayHasKey('ddd.safety.domain_no_dd', $rules);
        $this->assertArrayHasKey('ddd.safety.application_no_exit', $rules);
    }

    public function testDddPresetCanSkipOptionalFinalRules(): void
    {
        $architecture = Architecture::define();

        Preset::DDD(
            enforceFinalEntities: false,
            enforceFinalValueObjects: false,
            enforceFinalEvents: false,
        )->apply($architecture);

        $rules = $architecture->getRules();
        $this->assertArrayNotHasKey(DddPreset::ENTITY_MUST_BE_FINAL, $rules);
        $this->assertArrayNotHasKey(DddPreset::VALUE_OBJECT_MUST_BE_FINAL, $rules);
        $this->assertArrayNotHasKey(DddPreset::EVENT_MUST_BE_FINAL, $rules);
        $this->assertArrayHasKey(DddPreset::ENTITY_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey(DddPreset::EVENT_NO_DATETIME, $rules);
    }

    public function testDddPresetDoesNotReplaceConfiguredLayers(): void
    {
        $architecture = Architecture::define()
            ->layer('Domain', 'packages/Domain/');

        Preset::DDD()->apply($architecture);

        $this->assertSame(
            [
                'Domain'         => 'packages/Domain/',
                'Application'    => 'src/Application/',
                'Infrastructure' => 'src/Infrastructure/',
            ],
            $architecture->getLayers()
        );
    }

    public function testPsr1AndPsr12BothEnabledDoNotDuplicatePsr1Rules(): void
    {
        $architecture = Architecture::define();

        $architecture
            ->withPreset(Preset::PSR1(sourcePaths: ['src/']))
            ->withPreset(Preset::PSR12(sourcePaths: ['src/']));

        $rules = $architecture->getRules();

        $this->assertCount(14, $rules);

        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_UTF8_WITHOUT_BOM, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS, $rules);
        $this->assertArrayHasKey(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_NOT_BE_ROOT, $rules);
        $this->assertArrayHasKey(Psr4Preset::NAMESPACE_PREFIX_MUST_NOT_BE_EMPTY, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASSES_MUST_BE_STUDLY_CAPS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASS_CONSTANTS_MUST_BE_UPPER_CASE, $rules);
        $this->assertArrayHasKey(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE, $rules);
        $this->assertArrayHasKey(Psr12Preset::METHODS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::CONSTANTS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::PROPERTIES_MUST_DECLARE_VISIBILITY, $rules);
    }

    public function testSourceLayerNameIsDisambiguatedWhenPresetsHaveDifferentSourcePaths(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4(sourcePaths: ['src/'])->apply($architecture);
        Preset::PSR1(sourcePaths: ['lib/'])->apply($architecture);

        $layers = $architecture->getLayers();
        $this->assertArrayHasKey('Source[lib/]', $layers);
        $this->assertSame(['lib/'], $layers['Source[lib/]']);
    }

    public function testSourceLayerIsReusedWhenSourcePathsMatchRegardlessOfTrailingSlash(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4(sourcePaths: ['src/'])->apply($architecture);
        Preset::PSR1(sourcePaths: ['src'])->apply($architecture);

        $layers = $architecture->getLayers();
        $this->assertArrayHasKey('Source', $layers);
        $this->assertArrayNotHasKey('Source[src/]', $layers);
        $this->assertArrayNotHasKey('Source[src]', $layers);
    }

    public function testSourceLayerIsReusedWhenSourcePathsMatchRegardlessOfSeparator(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4(sourcePaths: ['src/'])->apply($architecture);
        Preset::PSR1(sourcePaths: ['src\\'])->apply($architecture);

        $layers = $architecture->getLayers();
        $this->assertArrayHasKey('Source', $layers);
        $this->assertArrayNotHasKey('Source[src/]', $layers);
        $this->assertArrayNotHasKey('Source[src\\]', $layers);
    }

    public function testSourceLayerIsReusedWhenSourcePathsAreTheSameRegardlessOfOrder(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4(sourcePaths: ['lib/', 'src/'])->apply($architecture);
        Preset::PSR1(sourcePaths: ['src/', 'lib/'])->apply($architecture);

        $layers = $architecture->getLayers();
        $this->assertArrayHasKey('Source', $layers);
        $this->assertArrayNotHasKey('Source[lib/,src/]', $layers);
        $this->assertArrayNotHasKey('Source[src/,lib/]', $layers);
    }

    public function testDisambiguatedSourceLayerNameIsSortedCanonically(): void
    {
        $architecture = Architecture::define();

        Preset::PSR4(sourcePaths: ['src/'])->apply($architecture);
        Preset::PSR1(sourcePaths: ['tests/', 'lib/'])->apply($architecture);

        $layers = $architecture->getLayers();
        $this->assertArrayHasKey('Source[lib/,tests/]', $layers);
        $this->assertArrayNotHasKey('Source[tests/,lib/]', $layers);
    }

    public function testMvcPresetRegistersAllRules(): void
    {
        $architecture = Architecture::define();

        Preset::MVC(
            controllerMaxComplexity: 4,
            controllerMaxMethodLength: 10,
            controllerMaxDependencies: 3,
            viewMaxComplexity: 2,
        )->apply($architecture);

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(MvcPreset::CONTROLLER_NAME_MUST_END_WITH_CONTROLLER, $rules);
        $this->assertArrayHasKey(MvcPreset::CONTROLLER_MAX_COMPLEXITY, $rules);
        $this->assertArrayHasKey(MvcPreset::MODEL_NAME_MUST_NOT_START_WITH_MODEL, $rules);
        $this->assertArrayHasKey(MvcPreset::MODEL_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey(MvcPreset::VIEW_NO_SUPERGLOBALS, $rules);
        $this->assertArrayHasKey(MvcPreset::SERVICE_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey('mvc.safety.controller_no_dd', $rules);
        $this->assertArrayHasKey('mvc.safety.service_no_exit', $rules);
    }
}
