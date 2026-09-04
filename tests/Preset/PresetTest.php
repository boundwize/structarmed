<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Preset;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\CodeQualityPreset;
use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Preset\Presets\MvcPreset;
use Boundwize\StructArmed\Preset\Presets\PerPreset;
use Boundwize\StructArmed\Preset\Presets\Psr12Preset;
use Boundwize\StructArmed\Preset\Presets\Psr15Preset;
use Boundwize\StructArmed\Preset\Presets\Psr1Preset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Preset\Presets\ResolvesSourceLayerNameTrait;
use Boundwize\StructArmed\Preset\Presets\YagniPreset;
use Boundwize\StructArmed\Rule\Rules\Class_\ExtendedClassMustBeAbstractOrInstantiatedRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MayNotExtendClassRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedAbstractClassRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedInterfaceRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedTraitRule;
use Boundwize\StructArmed\Rule\Rules\File\LargeNumericLiteralMustUseSeparatorRule;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeStaticAnonymousFunctionRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Preset::class)]
#[CoversClass(CodeQualityPreset::class)]
#[CoversClass(DddPreset::class)]
#[CoversClass(MvcPreset::class)]
#[CoversClass(PerPreset::class)]
#[CoversClass(Psr1Preset::class)]
#[CoversClass(Psr12Preset::class)]
#[CoversClass(Psr15Preset::class)]
#[CoversClass(Psr4Preset::class)]
#[CoversClass(ResolvesSourceLayerNameTrait::class)]
#[CoversClass(YagniPreset::class)]
final class PresetTest extends TestCase
{
    public function testYagniPresetRegistersSourceLayerAndRules(): void
    {
        $architecture = Architecture::define();

        Preset::YAGNI(
            sourcePaths: ['src/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertInstanceOf(
            MustBeUsedInterfaceRule::class,
            $rules[YagniPreset::INTERFACE_MUST_BE_USED] ?? null
        );
        $this->assertInstanceOf(
            MustBeUsedAbstractClassRule::class,
            $rules[YagniPreset::ABSTRACT_CLASS_MUST_BE_USED] ?? null
        );
        $this->assertInstanceOf(
            MustBeUsedTraitRule::class,
            $rules[YagniPreset::TRAIT_MUST_BE_USED] ?? null
        );
        $this->assertInstanceOf(
            ExtendedClassMustBeAbstractOrInstantiatedRule::class,
            $rules[YagniPreset::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED] ?? null
        );
    }

    public function testYagniPresetUsesComposerSourcePathsByDefault(): void
    {
        $architecture = Architecture::define();

        Preset::YAGNI()->apply($architecture);

        // A null source path list defers to Composer-discovered PSR-4 paths.
        $this->assertSame(['Source' => []], $architecture->getLayers());
    }

    public function testCodeQualityPresetRegistersSourceLayerAndRules(): void
    {
        $architecture = Architecture::define();

        Preset::CODEQUALITY(
            sourcePaths: ['src/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertCount(2, $rules);
        $this->assertInstanceOf(
            MustBeStaticAnonymousFunctionRule::class,
            $rules[CodeQualityPreset::ANONYMOUS_FUNCTIONS_MUST_BE_STATIC] ?? null
        );
        $this->assertInstanceOf(
            LargeNumericLiteralMustUseSeparatorRule::class,
            $rules[CodeQualityPreset::LARGE_NUMERIC_LITERALS_MUST_USE_SEPARATOR] ?? null
        );
    }

    public function testCodeQualityPresetUsesComposerSourcePathsByDefault(): void
    {
        $architecture = Architecture::define();

        Preset::CODEQUALITY()->apply($architecture);

        // A null source path list defers to Composer-discovered PSR-4 paths.
        $this->assertSame(['Source' => []], $architecture->getLayers());
        $this->assertArrayHasKey(
            CodeQualityPreset::ANONYMOUS_FUNCTIONS_MUST_BE_STATIC,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            CodeQualityPreset::LARGE_NUMERIC_LITERALS_MUST_USE_SEPARATOR,
            $architecture->getRules()
        );
    }

    public function testPsr1PresetRegistersSourceLayerAndRules(): void
    {
        $architecture = Architecture::define();

        Preset::PSR1(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_UTF8, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_UTF8_WITHOUT_BOM, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS, $rules);
        $this->assertArrayHasKey(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASSES_MUST_BE_STUDLY_CAPS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASS_CONSTANTS_MUST_BE_UPPER_CASE, $rules);
        $this->assertArrayHasKey(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE, $rules);
    }

    public function testPsr12PresetAppliesPsr1RulesAndAddsPsr12Rules(): void
    {
        $architecture = Architecture::define();

        Preset::PSR12(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_UTF8, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_UTF8_WITHOUT_BOM, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS, $rules);
        $this->assertArrayHasKey(Psr4Preset::CLASSES_MUST_MATCH_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_BE_IN_COMPOSER, $rules);
        $this->assertArrayHasKey(Psr4Preset::SOURCE_PATHS_MUST_EXIST_ON_DISK, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASSES_MUST_BE_STUDLY_CAPS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASS_CONSTANTS_MUST_BE_UPPER_CASE, $rules);
        $this->assertArrayHasKey(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE, $rules);
        $this->assertArrayHasKey(Psr12Preset::FILES_MUST_USE_LOWERCASE_KEYWORD_CONSTANTS, $rules);
        $this->assertArrayHasKey(Psr12Preset::METHODS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::CONSTANTS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::PROPERTIES_MUST_DECLARE_VISIBILITY, $rules);
    }

    public function testPerPresetAppliesPsr12RulesAndAddsEnumCaseRule(): void
    {
        $architecture = Architecture::define();

        Preset::PER(
            sourcePaths: ['src/', 'tests/'],
        )->apply($architecture);

        $this->assertSame(['Source' => ['src/', 'tests/']], $architecture->getLayers());

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASSES_MUST_BE_STUDLY_CAPS, $rules);
        $this->assertArrayHasKey(Psr1Preset::CLASS_CONSTANTS_MUST_BE_UPPER_CASE, $rules);
        $this->assertArrayHasKey(Psr1Preset::METHODS_MUST_BE_CAMEL_CASE, $rules);
        $this->assertArrayHasKey(Psr12Preset::FILES_MUST_USE_LOWERCASE_KEYWORD_CONSTANTS, $rules);
        $this->assertArrayHasKey(Psr12Preset::METHODS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::CONSTANTS_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(Psr12Preset::PROPERTIES_MUST_DECLARE_VISIBILITY, $rules);
        $this->assertArrayHasKey(PerPreset::ENUM_CASES_MUST_BE_PASCAL_CASE, $rules);
        $this->assertArrayHasKey(PerPreset::ENUM_METHODS_MAY_NOT_BE_PROTECTED, $rules);
        $this->assertArrayHasKey(PerPreset::ENUM_CONSTANTS_MAY_NOT_BE_PROTECTED, $rules);
        $this->assertArrayHasKey(PerPreset::ANONYMOUS_CLASSES_MAY_NOT_HAVE_EMPTY_PARENTHESES, $rules);
    }

    public function testPerPresetUsesComposerSourcePathsByDefault(): void
    {
        $architecture = Architecture::define();

        Preset::PER()->apply($architecture);

        $this->assertSame(['Source' => []], $architecture->getLayers());
        $this->assertArrayHasKey(
            PerPreset::ENUM_CASES_MUST_BE_PASCAL_CASE,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            PerPreset::ENUM_METHODS_MAY_NOT_BE_PROTECTED,
            $architecture->getRules()
        );
        $this->assertArrayHasKey(
            PerPreset::ENUM_CONSTANTS_MAY_NOT_BE_PROTECTED,
            $architecture->getRules()
        );
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
        $this->assertInstanceOf(
            MayNotExtendClassRule::class,
            $rules[DddPreset::DOMAIN_MUST_NOT_EXTEND_DOCTRINE_ENTITY_REPOSITORY] ?? null
        );
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

        $this->assertCount(16, $rules);

        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_TAGS, $rules);
        $this->assertArrayHasKey(Psr1Preset::FILES_MUST_USE_VALID_UTF8, $rules);
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
        $this->assertArrayHasKey(Psr12Preset::FILES_MUST_USE_LOWERCASE_KEYWORD_CONSTANTS, $rules);
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

    public function testInheritedPresetLayerNameDescribesItsEffectiveSourcePaths(): void
    {
        $architecture = Architecture::define()
            ->withPreset(Preset::PSR12(sourcePaths: ['tests/']))
            ->withPreset(Preset::PSR1(sourcePaths: ['src/']));

        $layers = $architecture->getLayers();

        $this->assertArrayNotHasKey('Source[src/]', $layers);
        $this->assertSame(['tests/', 'src/'], $layers['Source[src/,tests/]']);
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

        $this->assertSame(
            [
                'Controller' => [
                    'src/Controller/',
                    'src/Controllers/',
                    'app/Controllers/',
                    'app/Http/Controllers/',
                ],
                'Model'      => [
                    'src/Model/',
                    'src/Models/',
                    'app/Models/',
                ],
                'View'       => 'src/View/',
                'Service'    => 'src/Service/',
                'Helper'     => [
                    'src/Helper/',
                    'src/Helpers/',
                    'app/Helpers/',
                ],
            ],
            $architecture->getLayers()
        );
        $this->assertSame([
            'Controller' => [
                'pattern'        => '/(?:^|\\\\)Controllers?(?:\\\\|$)/',
                'excludePattern' => '/(?:^|\\\\)[^\\\\]*Tests?(?:\\\\|$)/',
            ],
            'Model'      => [
                'pattern'        => '/(?:^|\\\\)Models?(?:\\\\|$)/',
                'excludePattern' => '/(?:^|\\\\)[^\\\\]*Tests?(?:\\\\|$)/',
            ],
            'View'       => [
                'pattern'        => '/(?:^|\\\\)Views?(?:\\\\|$)/',
                'excludePattern' => '/(?:^|\\\\)[^\\\\]*Tests?(?:\\\\|$)/',
            ],
            'Service'    => [
                'pattern'        => '/(?:^|\\\\)Services?(?:\\\\|$)/',
                'excludePattern' => '/(?:^|\\\\)[^\\\\]*Tests?(?:\\\\|$)/',
            ],
            'Helper'     => [
                'pattern'        => '/(?:^|\\\\)Helpers?(?:\\\\|$)/',
                'excludePattern' => '/(?:^|\\\\)[^\\\\]*Tests?(?:\\\\|$)/',
            ],
        ], $architecture->getLayerPatterns());

        $rules = $architecture->getRules();
        $this->assertArrayHasKey(MvcPreset::CONTROLLER_NAME_MUST_END_WITH_CONTROLLER, $rules);
        $this->assertArrayHasKey(MvcPreset::CONTROLLER_MAX_COMPLEXITY, $rules);
        $this->assertArrayHasKey(MvcPreset::MODEL_NAME_MUST_NOT_START_WITH_MODEL, $rules);
        $this->assertArrayHasKey(MvcPreset::MODEL_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey(MvcPreset::VIEW_NO_SUPERGLOBALS, $rules);
        $this->assertArrayHasKey(MvcPreset::SERVICE_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey(MvcPreset::HELPER_MUST_HAVE_RETURN_TYPES, $rules);
        $this->assertArrayHasKey('mvc.safety.controller_no_dd', $rules);
        $this->assertArrayHasKey('mvc.safety.service_no_exit', $rules);
    }

    public function testMvcPresetDoesNotReplaceConfiguredLayersOrPatterns(): void
    {
        $architecture = Architecture::define()
            ->layer('Controller', 'app/Http/Controllers/')
            ->layerPattern('Controller', '/^Custom\\\\Http\\\\Controller\\\\/');

        Preset::MVC()->apply($architecture);

        $this->assertSame(
            [
                'Controller' => 'app/Http/Controllers/',
                'Model'      => [
                    'src/Model/',
                    'src/Models/',
                    'app/Models/',
                ],
                'View'       => 'src/View/',
                'Service'    => 'src/Service/',
                'Helper'     => [
                    'src/Helper/',
                    'src/Helpers/',
                    'app/Helpers/',
                ],
            ],
            $architecture->getLayers()
        );
        $this->assertSame(
            '/^Custom\\\\Http\\\\Controller\\\\/',
            $architecture->getLayerPatterns()['Controller']['pattern']
        );
        $this->assertSame(
            '/(?:^|\\\\)Models?(?:\\\\|$)/',
            $architecture->getLayerPatterns()['Model']['pattern']
        );
    }
}
