<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Config;

use Boundwize\StructArmed\Architecture;
use RuntimeException;

use function array_fill_keys;
use function array_unique;
use function array_values;
use function file_exists;
use function get_included_files;
use function realpath;
use function sprintf;

final class ConfigLoader
{
    /**
     * @param list<string> $configFiles
     * @param-out list<string> $configFiles
     */
    public static function load(string $configPath, array &$configFiles = []): Architecture
    {
        if (! file_exists($configPath)) {
            throw new RuntimeException(sprintf(
                'StructArmed config file not found at [%s]. '
                . 'Create a structarmed.php file in your project root.',
                $configPath
            ));
        }

        $previouslyIncludedFiles = array_fill_keys(get_included_files(), true);
        $architecture            = require $configPath;

        if (! $architecture instanceof Architecture) {
            throw new RuntimeException(sprintf(
                'StructArmed config file [%s] must return an instance of %s.',
                $configPath,
                Architecture::class
            ));
        }

        $rootConfigPath = realpath($configPath) ?: $configPath;
        $configFiles    = [$rootConfigPath];

        foreach (get_included_files() as $includedFile) {
            if (isset($previouslyIncludedFiles[$includedFile]) || $includedFile === $rootConfigPath) {
                continue;
            }

            $configFiles[] = $includedFile;
        }

        $configFiles = array_values(array_unique($configFiles));

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
