<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Util\Path;

use function array_map;
use function implode;
use function sort;

trait ResolvesSourceLayerNameTrait
{
    public const SOURCE_LAYER = 'Source';

    public function resolveLayerName(Architecture $architecture): string
    {
        $sourcePaths    = $this->normalizePaths($this->sourcePaths ?? []);
        $existingLayers = $architecture->getLayers();

        if (! isset($existingLayers[self::SOURCE_LAYER])) {
            return self::SOURCE_LAYER;
        }

        $existing = $this->normalizePaths((array) $existingLayers[self::SOURCE_LAYER]);

        sort($existing);
        sort($sourcePaths);

        if ($existing === $sourcePaths) {
            return self::SOURCE_LAYER;
        }

        return self::SOURCE_LAYER . '[' . implode(',', $sourcePaths) . ']';
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        return array_map(
            static fn (string $path): string => Path::normalise($path) . '/',
            $paths
        );
    }
}
