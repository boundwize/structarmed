<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use function array_fill_keys;
use function array_intersect_key;
use function hash_file;
use function is_array;
use function is_string;
use function stat;
use function time;

use const PHP_OS_FAMILY;

/**
 * Provides memoised content hashes for files that are treated as immutable
 * during a single analysis run.
 *
 * Hashes from a previous run can be reused through fingerprints: a file whose
 * stat signature is unchanged keeps its stored hash without being read again.
 */
final class FileHashProvider
{
    /** @var array<string, string> */
    private array $hashes = [];

    /** @var array<string, array{string, string}> stat signature and content hash per file */
    private array $fingerprints = [];

    private bool $hasNewFingerprints = false;

    /**
     * A file changed within the run's starting second could change again in
     * that second without changing its signature, so only a file whose ctime
     * is older is fingerprinted. Windows reports the creation time as ctime,
     * so a same-size rewrite that keeps the mtime would go unnoticed there.
     */
    private readonly int $fingerprintBefore;

    public function __construct()
    {
        $this->fingerprintBefore = PHP_OS_FAMILY === 'Windows' ? 0 : time();
    }

    public function hash(string $file): string
    {
        if (isset($this->hashes[$file])) {
            return $this->hashes[$file];
        }

        $stat = @stat($file);

        if ($stat === false) {
            return '';
        }

        $signature   = $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['size'] . ':' . $stat['ino'];
        $fingerprint = $this->fingerprints[$file] ?? null;

        if ($fingerprint !== null && $fingerprint[0] === $signature) {
            return $this->hashes[$file] = $fingerprint[1];
        }

        $hash = @hash_file('xxh128', $file);

        if ($hash === false) {
            return '';
        }

        if ($stat['ctime'] < $this->fingerprintBefore) {
            $this->fingerprints[$file] = [$signature, $hash];
            $this->hasNewFingerprints  = true;
        }

        return $this->hashes[$file] = $hash;
    }

    /**
     * @param array<string, mixed> $fingerprints
     */
    public function useFingerprints(array $fingerprints): void
    {
        foreach ($fingerprints as $file => $fingerprint) {
            if (
                is_array($fingerprint)
                && isset($fingerprint[0], $fingerprint[1])
                && is_string($fingerprint[0])
                && is_string($fingerprint[1])
            ) {
                $this->fingerprints[$file] = [$fingerprint[0], $fingerprint[1]];
            }
        }
    }

    /**
     * @return array<string, array{string, string}>|null null when nothing new was fingerprinted
     */
    public function newFingerprints(): ?array
    {
        if (! $this->hasNewFingerprints) {
            return null;
        }

        $this->hasNewFingerprints = false;

        return $this->fingerprints;
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
        $this->hashes             = [];
        $this->fingerprints       = [];
        $this->hasNewFingerprints = false;
    }
}
