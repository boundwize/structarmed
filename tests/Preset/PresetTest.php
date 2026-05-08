<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Preset;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Preset\Presets\MvcPreset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Preset::class)]
#[CoversClass(DddPreset::class)]
#[CoversClass(MvcPreset::class)]
#[CoversClass(Psr4Preset::class)]
final class PresetTest extends TestCase
{
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
        $this->assertArrayHasKey(MvcPreset::CONTROLLER_NOT_DEPEND_VIEW, $rules);
        $this->assertArrayHasKey(MvcPreset::CONTROLLER_MAX_COMPLEXITY, $rules);
        $this->assertArrayHasKey(MvcPreset::MODEL_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey(MvcPreset::VIEW_NO_SUPERGLOBALS, $rules);
        $this->assertArrayHasKey(MvcPreset::SERVICE_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey('mvc.safety.controller_no_dd', $rules);
        $this->assertArrayHasKey('mvc.safety.service_no_exit', $rules);
    }
}
