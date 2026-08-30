<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Composer;

use Boundwize\StructArmed\Util\Path;

use function clearstatcache;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function filesize;
use function is_array;
use function is_int;
use function json_decode;

/**
 * Provides memoised decoded composer.json contents. The memo is shared across
 * instances because rules and presets create their own Psr4PathResolver; it is
 * validated against the file's mtime and size, so a rewrite (e.g. by a fixer)
 * is re-decoded, and AnalysisResultCache::clear() drops it entirely.
 */
final class ComposerJsonProvider
{
    /** @var array<string, array{string, array<string, mixed>|null}> */
    private static array $decodedByFile = [];

    /**
     * @return array<string, mixed>|null
     */
    public function config(string $basePath): ?array
    {
        $composerFile = Path::normalise(Path::resolve('composer.json', $basePath), canonicalise: true);

        clearstatcache(true, $composerFile);

        $stat = file_exists($composerFile) ? filemtime($composerFile) . ':' . filesize($composerFile) : '';

        if (isset(self::$decodedByFile[$composerFile]) && self::$decodedByFile[$composerFile][0] === $stat) {
            return self::$decodedByFile[$composerFile][1];
        }

        $config = $stat === '' ? null : $this->decode((string) file_get_contents($composerFile));

        self::$decodedByFile[$composerFile] = [$stat, $config];

        return $config;
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
