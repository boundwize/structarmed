<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\EnumCaseValueMustNotBeEmptyRule;

/**
 * Enum preset: conventions for declaring enums, independent of any coding
 * standard — a string-backed enum case must not be backed by an empty string.
 *
 * Trade-off: only backing values the analyser resolves statically are
 * checked; a case backed by an expression it cannot evaluate is skipped.
 */
final readonly class EnumPreset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const CASE_VALUE_MUST_NOT_BE_EMPTY = 'enum.case_value.must_not_be_empty';

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
            self::CASE_VALUE_MUST_NOT_BE_EMPTY,
            new EnumCaseValueMustNotBeEmptyRule($layerName)
        );
    }
}
