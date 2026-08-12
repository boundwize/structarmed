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
    use ResolvesSourceLayerNameTrait;

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
        $sourcePathsForPsr12 = $architecture->registerPresetSourcePaths(self::class, $this->sourcePaths);
        $sourcePathsForPsr1  = $architecture->registerPresetSourcePaths(Psr1Preset::class, $this->sourcePaths);

        $psr1Preset = new Psr1Preset($sourcePathsForPsr1);
        $psr1Preset->apply($architecture);

        // Only the inherited PSR-1 preset receives the combined scope. The PSR-12
        // rules intentionally use this preset's configured paths so standalone
        // PSR-1 or PSR-4 scopes cannot broaden PSR-12 enforcement.
        $layerName = $this->resolveLayerName($architecture);
        $architecture->layer($layerName, $sourcePathsForPsr12 ?? []);

        $architecture->rule(
            self::METHODS_MUST_DECLARE_VISIBILITY,
            new MustDeclareMethodVisibilityRule($layerName)
        );
        $architecture->rule(
            self::CONSTANTS_MUST_DECLARE_VISIBILITY,
            new MustDeclareConstantVisibilityRule($layerName)
        );
        $architecture->rule(
            self::PROPERTIES_MUST_DECLARE_VISIBILITY,
            new MustDeclarePropertyVisibilityRule($layerName)
        );
    }
}
