<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Config;

use Boundwize\StructArmed\Architecture;
use RuntimeException;

use function file_exists;
use function is_callable;
use function sprintf;

final class ConfigLoader
{
    public static function load(string $configPath): Architecture
    {
        if (! file_exists($configPath)) {
            throw new RuntimeException(sprintf(
                'StructArmed config file not found at [%s]. '
                . 'Create a structarmed.php file in your project root.',
                $configPath
            ));
        }

        $config = require $configPath;

        if ($config instanceof Architecture) {
            return $config;
        }

        // a config file written for Architecture::import() returns a callable,
        // so it can be used as the main config file as well
        if (is_callable($config)) {
            $architecture = Architecture::define();
            $config($architecture);

            return $architecture;
        }

        throw new RuntimeException(sprintf(
            'StructArmed config file [%s] must return an instance of %s or a callable that configures one.',
            $configPath,
            Architecture::class
        ));
    }

    /**
     * Searches the base paths in order, so the CLI's working directory wins over
     * a --basepath project root that also holds a config file.
     */
    public static function discover(string ...$basePaths): string
    {
        foreach ($basePaths as $basePath) {
            $candidates = [
                $basePath . '/structarmed.php',
                $basePath . '/structarmed.dist.php',
            ];

            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException(
            'Could not find a structarmed.php config file. '
            . 'Run `vendor/bin/structarmed init` to generate one.'
        );
    }
}
