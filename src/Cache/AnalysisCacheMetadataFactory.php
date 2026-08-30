<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Version;
use Composer\InstalledVersions;

use function array_map;
use function file_exists;
use function hash;
use function json_encode;
use function rtrim;
use function sort;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * @internal
 */
final readonly class AnalysisCacheMetadataFactory
{
    public function __construct(private FileHashProvider $fileHashProvider)
    {
    }

    /**
     * @param list<string> $scanPaths
     * @param list<string> $files
     * @return array<string, mixed>
     */
    public function metadata(string $basePath, string $configPath, array $scanPaths, array $files): array
    {
        sort($files);

        return [
            'version'                      => 4,
            'basePath'                     => $basePath,
            'configPath'                   => $configPath,
            'configHash'                   => $this->fileHash($configPath),
            'composerGeneratedVersionHash' => $this->composerGeneratedVersionHash(),
            'composerHash'                 => $this->composerHash($basePath),
            'scanPaths'                    => $scanPaths,
            'filesHash'                    => $this->filesHash($files),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function key(array $metadata): string
    {
        return hash('xxh128', json_encode([
            'version'                      => $metadata['version'] ?? null,
            'basePath'                     => $metadata['basePath'] ?? null,
            'configPath'                   => $metadata['configPath'] ?? null,
            'composerGeneratedVersionHash' => $metadata['composerGeneratedVersionHash'] ?? null,
            'scanPaths'                    => $metadata['scanPaths'] ?? [],
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public function fileHash(string $path): string
    {
        return $this->fileHashProvider->hash($path);
    }

    /**
     * Cached analysis nodes store resolved layer assignments, which depend on the
     * composer.json PSR-4 mappings as well as the config, so both hashes must
     * key the namespace or a composer.json change would reuse stale layers.
     */
    public function analysisNodeCacheNamespace(string $basePath, string $configHash): string
    {
        return hash('xxh128', $configHash . "\0" . $this->composerHash($basePath));
    }

    /**
     * @param list<string> $files
     */
    private function filesHash(array $files): string
    {
        return hash('xxh128', json_encode(array_map(fn(string $file): array => [
            'file' => $file,
            'hash' => $this->fileHashProvider->hash($file),
        ], $files), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    private function composerHash(string $basePath): string
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

        return file_exists($composerFile) ? $this->fileHash($composerFile) : '';
    }

    public function composerGeneratedVersionHash(): string
    {
        $version = InstalledVersions::isInstalled(Version::PACKAGE_NAME)
            ? [
                'prettyVersion' => InstalledVersions::getPrettyVersion(Version::PACKAGE_NAME),
                'reference'     => InstalledVersions::getReference(Version::PACKAGE_NAME),
            ]
            : InstalledVersions::getRootPackage();

        return hash('xxh128', json_encode($version, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
