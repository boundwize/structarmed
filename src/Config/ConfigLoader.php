<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Config;

use Boundwize\StructArmed\Architecture;
use RuntimeException;

use function file_exists;
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

        $architecture = require $configPath;

        if (! $architecture instanceof Architecture) {
            throw new RuntimeException(sprintf(
                'StructArmed config file [%s] must return an instance of %s.',
                $configPath,
                Architecture::class
            ));
        }

        return $architecture;
    }

    public static function discover(string $basePath): string
    {
        $candidates = [
            $basePath . '/structarmed.php',
            $basePath . '/structarmed.dist.php',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Could not find a structarmed.php config file. '
            . 'Run `vendor/bin/structarmed init` to generate one.'
        );
    }
}
