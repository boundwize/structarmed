<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Config\ConfigLoader;
use RuntimeException;

use function count;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const PHP_EOL;

final readonly class ClearCacheCommand
{
    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $options = [];
        $counter = count($arguments);

        for ($i = 0; $i < $counter; $i++) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--config=')) {
                $options['config'] = substr($argument, strlen('--config='));
                continue;
            }

            if ($argument === '--config') {
                $options['config'] = $arguments[++$i] ?? '';
                continue;
            }

            echo sprintf("Unknown option: %s\n\n", $argument);
            echo Usage::render();

            return 1;
        }

        $cacheDirectory = null;

        try {
            $configFile     = $options['config'] ?? ConfigLoader::discover($basePath);
            $cacheDirectory = ConfigLoader::load($configFile)->getCacheDirectory();
        } catch (RuntimeException $runtimeException) {
            if (isset($options['config'])) {
                echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

                return 1;
            }
        }

        (new AnalysisResultCache($basePath, $cacheDirectory))->clear();

        echo "StructArmed cache cleared.\n";

        return 0;
    }
}
