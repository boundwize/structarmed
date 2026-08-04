<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cli;

use function getenv;
use function in_array;
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

        // FORCE_COLOR=0 disables color entirely, unlike CLICOLOR_FORCE=0
        // which only means "don't force" and falls through to detection.
        if (getenv('FORCE_COLOR') === '0') {
            return false;
        }

        foreach (['FORCE_COLOR', 'CLICOLOR_FORCE'] as $forceVariable) {
            if (! in_array(getenv($forceVariable), [false, '', '0'], true)) {
                return true;
            }
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
