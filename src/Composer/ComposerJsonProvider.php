<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Composer;

use Boundwize\StructArmed\Util\Path;

use function array_key_exists;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_int;
use function json_decode;

/**
 * Provides memoised decoded composer.json contents, treated as immutable during
 * a single analysis run: after the first call no filesystem access happens. The
 * memo is shared across instances because rules and presets create their own
 * Psr4PathResolver. AnalysisResultCache::clear() drops it, which the CLI runs
 * after every fix pass; any other caller that rewrites composer.json and then
 * re-evaluates in the same process must call clear() itself.
 */
final class ComposerJsonProvider
{
    /** @var array<string, array<string, mixed>|null> */
    private static array $decodedByFile = [];

    /**
     * @return array<string, mixed>|null
     */
    public function config(string $basePath): ?array
    {
        $composerFile = Path::normalise(Path::resolve('composer.json', $basePath), canonicalise: true);

        if (array_key_exists($composerFile, self::$decodedByFile)) {
            return self::$decodedByFile[$composerFile];
        }

        return self::$decodedByFile[$composerFile] = file_exists($composerFile)
            ? $this->decode((string) file_get_contents($composerFile))
            : null;
    }

    public function clear(): void
    {
        self::$decodedByFile = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $contents): ?array
    {
        $composer = json_decode($contents, true);

        if (! is_array($composer)) {
            return null;
        }

        $config = [];

        foreach ($composer as $key => $value) {
            if (is_int($key)) {
                return null;
            }

            $config[$key] = $value;
        }

        return $config;
    }
}
