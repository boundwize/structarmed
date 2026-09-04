<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use function array_fill_keys;
use function array_intersect_key;
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

    /**
     * @param list<string> $files
     */
    public function forFiles(array $files): self
    {
        $provider         = new self();
        $provider->hashes = array_intersect_key($this->hashes, array_fill_keys($files, true));

        return $provider;
    }

    public function clear(): void
    {
        $this->hashes = [];
    }
}
