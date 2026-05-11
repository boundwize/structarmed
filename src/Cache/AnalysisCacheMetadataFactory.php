<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Composer\InstalledVersions;

use function array_map;
use function file_get_contents;
use function hash;
use function json_encode;
use function sort;

use const JSON_THROW_ON_ERROR;

final readonly class AnalysisCacheMetadataFactory
{
    /**
     * @param list<string> $scanPaths
     * @param list<string> $files
     * @return array<string, mixed>
     */
    public function metadata(string $basePath, string $configPath, array $scanPaths, array $files): array
    {
        sort($files);

        return [
            'version'                      => 1,
            'basePath'                     => $basePath,
            'configPath'                   => $configPath,
            'configHash'                   => $this->fileHash($configPath),
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
            'composerGeneratedVersionHash' => $metadata['composerGeneratedVersionHash'] ?? null,
            'scanPaths'                    => $metadata['scanPaths'] ?? [],
        ], JSON_THROW_ON_ERROR));
    }

    public function fileHash(string $path): string
    {
        return hash('xxh128', (string) file_get_contents($path));
    }

    /**
     * @param list<string> $files
     */
    private function filesHash(array $files): string
    {
        return hash('xxh128', json_encode(array_map(static fn(string $file): array => [
            'file' => $file,
            'hash' => hash('xxh128', (string) file_get_contents($file)),
        ], $files), JSON_THROW_ON_ERROR));
    }

    public function composerGeneratedVersionHash(): string
    {
        $version = InstalledVersions::isInstalled('boundwize/structarmed')
            ? [
                'prettyVersion' => InstalledVersions::getPrettyVersion('boundwize/structarmed'),
                'reference'     => InstalledVersions::getReference('boundwize/structarmed'),
            ]
            : InstalledVersions::getRootPackage();

        return hash('xxh128', json_encode($version, JSON_THROW_ON_ERROR));
    }
}
