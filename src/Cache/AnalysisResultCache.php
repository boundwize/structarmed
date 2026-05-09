<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

use function array_keys;
use function array_map;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function rmdir;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strval;
use function sys_get_temp_dir;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final readonly class AnalysisResultCache
{
    private string $cacheDirectory;

    public function __construct(string $basePath, ?string $cacheDirectory = null)
    {
        $this->cacheDirectory = $cacheDirectory
            ? $this->resolveCacheDirectory($basePath, $cacheDirectory)
            : sys_get_temp_dir() . '/structarmed/cache/' . hash('xxh128', $basePath);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function load(string $key, array $metadata): ?RuleViolationCollection
    {
        $payload = $this->read($key);

        if ($payload === null) {
            return null;
        }

        if (($payload['metadata'] ?? null) !== $metadata) {
            return null;
        }

        if (! is_array($payload['violations'] ?? null)) {
            return null;
        }

        $ruleViolationCollection = new RuleViolationCollection();

        foreach ($payload['violations'] as $violation) {
            if (! is_array($violation)) {
                return null;
            }

            $ruleViolation = $this->ruleViolationFromArray($violation);

            if (! $ruleViolation instanceof RuleViolation) {
                return null;
            }

            $ruleViolationCollection->add($ruleViolation);
        }

        return $ruleViolationCollection;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function store(string $key, array $metadata, RuleViolationCollection $ruleViolationCollection): void
    {
        if (! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }

        file_put_contents($this->path($key), json_encode([
            'metadata'   => $metadata,
            'violations' => $ruleViolationCollection->toArray(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        foreach (array_map(strval(...), glob($this->cacheDirectory . '/*') ?: []) as $path) {
            if (is_dir($path)) {
                rmdir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($this->cacheDirectory);
    }

    public function hasDifferentConfig(string $configHash): bool
    {
        if (! is_dir($this->cacheDirectory)) {
            return false;
        }

        foreach (array_map(strval(...), glob($this->cacheDirectory . '/*') ?: []) as $path) {
            if (is_dir($path)) {
                continue;
            }

            $payload = $this->readPath($path);

            if ($payload === null) {
                continue;
            }

            $metadata = $payload['metadata'] ?? null;

            if (! is_array($metadata)) {
                continue;
            }

            if (($metadata['configHash'] ?? null) !== $configHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        return $this->readPath($this->path($key));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPath(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) && $this->hasOnlyStringKeys($payload) ? $payload : null;
    }

    /**
     * @param array<mixed, mixed> $violation
     */
    private function ruleViolationFromArray(array $violation): ?RuleViolation
    {
        if (! $this->hasOnlyStringKeys($violation)) {
            return null;
        }

        $ruleKey   = $violation['rule'] ?? null;
        $message   = $violation['message'] ?? null;
        $file      = $violation['file'] ?? null;
        $line      = $violation['line'] ?? null;
        $className = $violation['class'] ?? null;
        $layer     = $violation['layer'] ?? null;

        if (
            ! is_string($ruleKey)
            || ! is_string($message)
            || ! is_string($file)
            || ! is_int($line)
            || ! is_string($className)
            || ($layer !== null && ! is_string($layer))
        ) {
            return null;
        }

        return new RuleViolation(
            ruleKey:   $ruleKey,
            message:   $message,
            file:      $file,
            line:      $line,
            className: $className,
            layer:     $layer,
        );
    }

    /**
     * @param array<mixed, mixed> $array
     * @phpstan-assert-if-true array<string, mixed> $array
     */
    private function hasOnlyStringKeys(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }

    private function path(string $key): string
    {
        return sprintf('%s/%s.json', $this->cacheDirectory, $key);
    }

    private function resolveCacheDirectory(string $basePath, string $cacheDirectory): string
    {
        $cacheDirectory = str_replace('\\', '/', $cacheDirectory);

        if ($this->isAbsolutePath($cacheDirectory)) {
            return $cacheDirectory;
        }

        return sprintf('%s/%s', $basePath, $cacheDirectory);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }
}
