<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function str_ends_with;
use function str_starts_with;

final class SourceLayerName
{
    /**
     * 'Source', or its reserved disambiguated form 'Source[...]'.
     */
    public static function matches(string $layerName): bool
    {
        return $layerName === 'Source'
            || (str_starts_with($layerName, 'Source[') && str_ends_with($layerName, ']'));
    }
}
