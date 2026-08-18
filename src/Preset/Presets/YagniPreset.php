<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeImplementedInterfaceRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeOverriddenAbstractClassRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedTraitRule;

/**
 * YAGNI ("You Aren't Gonna Need It") preset: reports speculative abstractions
 * nothing in the scanned paths needs — interfaces no class implements and no
 * interface extends, abstract classes no class extends, and traits no
 * class-like uses.
 *
 * Trade-off: only usage within the scanned paths is known. Abstractions that
 * exist for consumers outside the scan (e.g. a published library's extension
 * points) should be excluded via skipRule() or skip paths.
 */
final readonly class YagniPreset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const INTERFACE_MUST_BE_IMPLEMENTED = 'yagni.interface.must_be_implemented';

    public const ABSTRACT_CLASS_MUST_BE_OVERRIDDEN = 'yagni.abstract_class.must_be_overridden';

    public const TRAIT_MUST_BE_USED = 'yagni.trait.must_be_used';

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
            self::INTERFACE_MUST_BE_IMPLEMENTED,
            new MustBeImplementedInterfaceRule($layerName)
        );
        $architecture->rule(
            self::ABSTRACT_CLASS_MUST_BE_OVERRIDDEN,
            new MustBeOverriddenAbstractClassRule($layerName)
        );
        $architecture->rule(
            self::TRAIT_MUST_BE_USED,
            new MustBeUsedTraitRule($layerName)
        );
    }
}
