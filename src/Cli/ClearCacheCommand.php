<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Cache\AnalysisResultCache;

final readonly class ClearCacheCommand
{
    public function run(string $basePath): int
    {
        (new AnalysisResultCache($basePath))->clear();

        echo "StructArmed cache cleared.\n";

        return 0;
    }
}
