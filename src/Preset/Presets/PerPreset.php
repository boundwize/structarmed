<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\AnonymousClassMayNotHaveEmptyParenthesesRule;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumCaseNameMustBePascalCaseRule;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumConstantMayNotBeProtectedRule;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumMethodMayNotBeProtectedRule;

/**
 * PER Coding Style: extends PSR-12 (which in turn requires PSR-1).
 *
 * @see https://www.php-fig.org/per/coding-style/
 */
final readonly class PerPreset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const ENUM_CASES_MUST_BE_PASCAL_CASE = 'per.enum_cases.must_be_pascal_case';

    public const ENUM_METHODS_MAY_NOT_BE_PROTECTED = 'per.enum_methods.may_not_be_protected';

    public const ENUM_CONSTANTS_MAY_NOT_BE_PROTECTED = 'per.enum_constants.may_not_be_protected';

    public const ANONYMOUS_CLASSES_MAY_NOT_HAVE_EMPTY_PARENTHESES =
        'per.anonymous_classes.may_not_have_empty_parentheses';

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $sourcePathsForPer   = $architecture->registerPresetSourcePaths(self::class, $this->sourcePaths);
        $sourcePathsForPsr12 = $architecture->registerPresetSourcePaths(Psr12Preset::class, $this->sourcePaths);

        $psr12Preset = new Psr12Preset($sourcePathsForPsr12);
        $psr12Preset->apply($architecture);

        // PER rules use only paths accumulated for PER. Paths registered
        // exclusively for PSR-1, PSR-4, or PSR-12 must not broaden PER
        // enforcement.
        $layerName = $this->resolveLayerName($architecture, $sourcePathsForPer);
        $architecture->layer($layerName, $sourcePathsForPer ?? []);

        $architecture->rule(
            self::ENUM_CASES_MUST_BE_PASCAL_CASE,
            new EnumCaseNameMustBePascalCaseRule($layerName)
        );

        $architecture->rule(
            self::ENUM_METHODS_MAY_NOT_BE_PROTECTED,
            new EnumMethodMayNotBeProtectedRule($layerName)
        );

        $architecture->rule(
            self::ENUM_CONSTANTS_MAY_NOT_BE_PROTECTED,
            new EnumConstantMayNotBeProtectedRule($layerName)
        );

        $architecture->rule(
            self::ANONYMOUS_CLASSES_MAY_NOT_HAVE_EMPTY_PARENTHESES,
            new AnonymousClassMayNotHaveEmptyParenthesesRule($layerName)
        );
    }
}
