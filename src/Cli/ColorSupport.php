<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use function getenv;
use function stream_isatty;

use const STDOUT;

final class ColorSupport
{
    /**
     * CI environments that render ANSI colors in their build logs.
     */
    private const CI_ENVIRONMENT_VARIABLES = [
        'GITHUB_ACTIONS',
        'GITLAB_CI',
        'CIRCLECI',
        'TRAVIS',
        'BUILDKITE',
        'APPVEYOR',
        'TF_BUILD',
    ];

    /**
     * @param resource|null $stream
     */
    public static function detect(mixed $stream = null): bool
    {
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return false;
        }

        $forceColor = getenv('FORCE_COLOR');
        if ($forceColor !== false && $forceColor !== '') {
            return $forceColor !== '0';
        }

        $cliColorForce = getenv('CLICOLOR_FORCE');
        if ($cliColorForce !== false && $cliColorForce !== '' && $cliColorForce !== '0') {
            return true;
        }

        if (getenv('CLICOLOR') === '0') {
            return false;
        }

        foreach (self::CI_ENVIRONMENT_VARIABLES as $ciEnvironmentVariable) {
            if (getenv($ciEnvironmentVariable) !== false) {
                return true;
            }
        }

        if (getenv('TERM') === 'dumb') {
            return false;
        }

        if (getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON') {
            return true;
        }

        return stream_isatty($stream ?? STDOUT);
    }

    public static function wrap(string $value, string $code, bool $useColor): string
    {
        if (! $useColor || $value === '') {
            return $value;
        }

        return "\033[" . $code . 'm' . $value . "\033[0m";
    }
}
