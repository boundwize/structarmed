<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareConstantVisibilityRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclarePropertyVisibilityRule;

final readonly class Psr12Preset implements PresetInterface
{
    public const METHODS_MUST_DECLARE_VISIBILITY = 'psr12.methods.must_declare_visibility';

    public const CONSTANTS_MUST_DECLARE_VISIBILITY = 'psr12.constants.must_declare_visibility';

    public const PROPERTIES_MUST_DECLARE_VISIBILITY = 'psr12.properties.must_declare_visibility';

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        (new Psr1Preset($this->sourcePaths))->apply($architecture);

        $architecture->rule(
            self::METHODS_MUST_DECLARE_VISIBILITY,
            new MustDeclareMethodVisibilityRule(Psr1Preset::SOURCE_LAYER)
        );
        $architecture->rule(
            self::CONSTANTS_MUST_DECLARE_VISIBILITY,
            new MustDeclareConstantVisibilityRule(Psr1Preset::SOURCE_LAYER)
        );
        $architecture->rule(
            self::PROPERTIES_MUST_DECLARE_VISIBILITY,
            new MustDeclarePropertyVisibilityRule(Psr1Preset::SOURCE_LAYER)
        );
    }
}
