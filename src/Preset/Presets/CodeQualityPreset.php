<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\File\LargeNumericLiteralMustUseSeparatorRule;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeStaticAnonymousFunctionRule;

/**
 * Code quality preset: general readability and safety conventions that are
 * independent of any architecture style or coding standard.
 */
final readonly class CodeQualityPreset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const ANONYMOUS_FUNCTIONS_MUST_BE_STATIC = 'code_quality.anonymous_functions.must_be_static';

    public const LARGE_NUMERIC_LITERALS_MUST_USE_SEPARATOR =
        'code_quality.large_numeric_literals.must_use_separator';

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $layerName = $this->resolveLayerName($architecture);
        $architecture->layer($layerName, $this->sourcePaths ?? []);

        $architecture->rule(
            self::ANONYMOUS_FUNCTIONS_MUST_BE_STATIC,
            new MustBeStaticAnonymousFunctionRule($layerName)
        );
        $architecture->rule(
            self::LARGE_NUMERIC_LITERALS_MUST_USE_SEPARATOR,
            new LargeNumericLiteralMustUseSeparatorRule(sourcePaths: $this->sourcePaths)
        );
    }
}
