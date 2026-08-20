<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\ExtendedClassMustBeAbstractOrInstantiatedRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedAbstractClassRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedInterfaceRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeUsedTraitRule;

/**
 * YAGNI ("You Aren't Gonna Need It") preset: reports speculative abstractions
 * nothing in the scanned paths needs — interfaces no class implements and no
 * interface extends, abstract classes no class extends, traits no class-like
 * uses, and extended classes that are never instantiated and so should be
 * abstract.
 *
 * Trade-off: only usage within the scanned paths is known. Abstractions that
 * exist for consumers outside the scan (e.g. a published library's extension
 * points) should be excluded via skipRule() or skip paths.
 */
final readonly class YagniPreset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const INTERFACE_MUST_BE_USED = 'yagni.interface.must_be_used';

    public const ABSTRACT_CLASS_MUST_BE_USED = 'yagni.abstract_class.must_be_used';

    public const TRAIT_MUST_BE_USED = 'yagni.trait.must_be_used';

    public const EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED =
        'yagni.extended_class.must_be_abstract_or_instantiated';

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
            self::INTERFACE_MUST_BE_USED,
            new MustBeUsedInterfaceRule($layerName)
        );
        $architecture->rule(
            self::ABSTRACT_CLASS_MUST_BE_USED,
            new MustBeUsedAbstractClassRule($layerName)
        );
        $architecture->rule(
            self::TRAIT_MUST_BE_USED,
            new MustBeUsedTraitRule($layerName)
        );
        $architecture->rule(
            self::EXTENDED_CLASS_MUST_BE_ABSTRACT_OR_INSTANTIATED,
            new ExtendedClassMustBeAbstractOrInstantiatedRule($layerName)
        );
    }
}
