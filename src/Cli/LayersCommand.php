<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use Boundwize\StructArmed\Config\ConfigLoader;
use RuntimeException;

use function count;
use function explode;
use function implode;
use function is_array;
use function sprintf;

use const PHP_EOL;

/**
 * Prints all layers registered in the architecture config, including those
 * contributed by presets, without running a full analysis.
 */
final readonly class LayersCommand
{
    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, string $basePath): int
    {
        $configFile = null;
        $counter    = count($arguments);

        for ($index = 0; $index < $counter; $index++) {
            $argument         = $arguments[$index];
            [$option, $value] = explode('=', $argument, 2) + [1 => null];

            if ($option === '--config') {
                $configFile = $value ?? $arguments[++$index] ?? '';
                continue;
            }

            echo sprintf("Unknown option: %s\n\n", $argument);
            echo Usage::render();

            return 1;
        }

        try {
            if ($configFile === null) {
                $configFile = ConfigLoader::discover($basePath);
            }

            $architecture = ConfigLoader::load($configFile);
        } catch (RuntimeException $runtimeException) {
            echo 'Error: ' . $runtimeException->getMessage() . PHP_EOL;

            return 1;
        }

        $layers        = $architecture->getLayers();
        $layerPatterns = $architecture->getLayerPatterns();

        if ($layers === [] && $layerPatterns === []) {
            echo "No layers registered.\n";

            return 0;
        }

        foreach ($layers as $name => $paths) {
            $pathList = is_array($paths) ? $paths : [$paths];

            echo sprintf(
                "  %-24s path   %s\n",
                $name,
                implode(', ', $pathList)
            );
        }

        foreach ($layerPatterns as $name => $definition) {
            $patternList = is_array($definition['pattern'])
                ? $definition['pattern']
                : [$definition['pattern']];

            echo sprintf(
                "  %-24s regex  %s\n",
                $name,
                implode(', ', $patternList)
            );
        }

        return 0;
    }
}
