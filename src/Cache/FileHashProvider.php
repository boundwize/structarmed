<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use function hash_file;

/**
 * Provides memoised content hashes for files that are treated as immutable
 * during a single analysis run.
 */
final class FileHashProvider
{
    /** @var array<string, string> */
    private array $hashes = [];

    public function hash(string $file): string
    {
        if (isset($this->hashes[$file])) {
            return $this->hashes[$file];
        }

        $hash = hash_file('xxh128', $file);

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
