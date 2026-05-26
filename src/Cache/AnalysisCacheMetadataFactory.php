<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\Version;
use Composer\InstalledVersions;

use function array_map;
use function file_exists;
use function hash;
use function hash_file;
use function is_dir;
use function json_encode;
use function ltrim;
use function rtrim;
use function sort;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

final readonly class AnalysisCacheMetadataFactory
{
    private const METADATA_VERSION = 2;

    public function __construct(
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
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
            'version'                      => self::METADATA_VERSION,
            'basePath'                     => $basePath,
            'configPath'                   => $configPath,
            'configHash'                   => $this->fileHash($configPath),
            'composerJsonHash'             => $this->optionalFileHash(rtrim($basePath, '/') . '/composer.json'),
            'composerPsr4DirectoriesHash'  => $this->composerPsr4DirectoriesHash($basePath),
            'composerGeneratedVersionHash' => $this->composerGeneratedVersionHash(),
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
            'composerJsonHash'             => $metadata['composerJsonHash'] ?? null,
            'composerPsr4DirectoriesHash'  => $metadata['composerPsr4DirectoriesHash'] ?? null,
            'composerGeneratedVersionHash' => $metadata['composerGeneratedVersionHash'] ?? null,
            'scanPaths'                    => $metadata['scanPaths'] ?? [],
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public function fileHash(string $path): string
    {
        return (string) hash_file('xxh128', $path);
    }

    private function optionalFileHash(string $path): ?string
    {
        return file_exists($path) ? $this->fileHash($path) : null;
    }

    private function composerPsr4DirectoriesHash(string $basePath): string
    {
        $directories = [];

        foreach ($this->psr4PathResolver->namespacePaths($basePath) as $namespace => $paths) {
            foreach ($paths as $path) {
                $directories[] = [
                    'namespace'   => $namespace,
                    'path'        => $path,
                    'isDirectory' => is_dir(rtrim($basePath, '/') . '/' . ltrim($path, '/')),
                ];
            }
        }

        sort($directories);

        return hash('xxh128', json_encode($directories, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $files
     */
    private function filesHash(array $files): string
    {
        return hash('xxh128', json_encode(array_map(static fn(string $file): array => [
            'file' => $file,
            'hash' => (string) hash_file('xxh128', $file),
        ], $files), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
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
