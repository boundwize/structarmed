<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use function hash_file;

/**
 * Memoises content hashes so a file scanned by both the complete-result
 * manifest and the per-file class-node cache is only read from disk once
 * per run. Source files are treated as immutable while an analysis runs;
 * callers that modify files mid-run (e.g. --fix) must call {@see clear()}
 * before re-hashing.
 */
final class FileHashCache
{
    /** @var array<string, string> */
    private array $hashes = [];

    public function hash(string $file): string
    {
        if (isset($this->hashes[$file])) {
            return $this->hashes[$file];
        }

        $hash = hash_file('xxh128', $file);

        // Failures are not memoised: a file that becomes readable later in the
        // run must hash fresh instead of being pinned to ''.
        if ($hash === false) {
            return '';
        }

        return $this->hashes[$file] = $hash;
    }

    public function clear(): void
    {
        $this->hashes = [];
    }
}
