<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;

use function implode;
use function sort;

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

        $existing = (array) $existingLayers[self::SOURCE_LAYER];

        sort($existing);
        sort($sourcePaths);

        if ($existing === $sourcePaths) {
            return self::SOURCE_LAYER;
        }

        return self::SOURCE_LAYER . '[' . implode(',', $sourcePaths) . ']';
    }
}
