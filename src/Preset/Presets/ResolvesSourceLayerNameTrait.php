<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;

use function implode;

trait ResolvesSourceLayerNameTrait
{
    public const SOURCE_LAYER = 'Source';

    public function resolveLayerName(Architecture $architecture): string
    {
        $sourcePaths    = $this->sourcePaths ?? [];
        $existingLayers = $architecture->getLayers();

        if (! isset($existingLayers[self::SOURCE_LAYER])) {
            return self::SOURCE_LAYER;
        }

        if ((array) $existingLayers[self::SOURCE_LAYER] === $sourcePaths) {
            return self::SOURCE_LAYER;
        }

        return self::SOURCE_LAYER . '[' . implode(',', $sourcePaths) . ']';
    }
}
