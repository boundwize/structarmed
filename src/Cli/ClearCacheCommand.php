<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Config\ConfigLoader;
use Boundwize\StructArmed\Util\Path;
use RuntimeException;

use function count;
use function explode;
use function is_dir;
use function sprintf;

use const PHP_EOL;

final readonly class ClearCacheCommand
{
    private const VALUE_OPTIONS = [
        '--config'   => 'config',
        '--basepath' => 'basepath',
    ];

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $options = [];
        $counter = count($arguments);

        for ($i = 0; $i < $counter; $i++) {
            $argument       = $arguments[$i];
            $optionAndValue = explode('=', $argument, 2);
            $option         = $optionAndValue[0];

            if (isset(self::VALUE_OPTIONS[$option])) {
                $options[self::VALUE_OPTIONS[$option]] = $optionAndValue[1] ?? $arguments[++$i] ?? '';
                continue;
            }

            echo sprintf("Unknown option: %s\n\n", $argument);
            echo Usage::render();

            return 1;
        }

        $workingDirectory = $basePath;

        if (isset($options['basepath'])) {
            $basePath = Path::normalise(Path::resolve($options['basepath'], $workingDirectory), canonicalise: true);

            if (! is_dir($basePath)) {
                echo sprintf("Error: base path [%s] not found.\n", $options['basepath']);

                return 1;
            }
        }

        $cacheDirectory = null;

        try {
            $configFile     = $options['config'] ?? ConfigLoader::discover($workingDirectory, $basePath);
            $cacheDirectory = ConfigLoader::load($configFile)->getCacheDirectory();
        } catch (RuntimeException $runtimeException) {
            if (isset($options['config'])) {
                echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

                return 1;
            }
        }

        (new AnalysisResultCache($basePath, new FileHashProvider(), $cacheDirectory))->clear();

        echo "StructArmed cache cleared.\n";

        return 0;
    }
}
