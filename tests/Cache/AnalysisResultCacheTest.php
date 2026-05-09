<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(AnalysisCacheMetadataFactory::class)]
#[CoversClass(AnalysisResultCache::class)]
final class AnalysisResultCacheTest extends TestCase
{
    public function testStoresAndLoadsViolationCollection(): void
    {
        $cacheDirectory          = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache(__DIR__, $cacheDirectory);
        $metadata                = ['configHash' => 'same', 'filesHash' => 'same'];
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add(new RuleViolation(
            ruleKey:   'rule',
            message:   'Nope',
            file:      __FILE__,
            line:      10,
            className: self::class,
            layer:     'Domain',
        ));

        try {
            $analysisResultCache->store('key', $metadata, $ruleViolationCollection);
            $loaded = $analysisResultCache->load('key', $metadata);

            $this->assertInstanceOf(RuleViolationCollection::class, $loaded);
            $this->assertSame($ruleViolationCollection->toArray(), $loaded->toArray());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenMetadataChanges(): void
    {
        $cacheDirectory          = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache(__DIR__, $cacheDirectory);
        $ruleViolationCollection = new RuleViolationCollection();

        try {
            $analysisResultCache->store('key', ['configHash' => 'old'], $ruleViolationCollection);

            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', ['configHash' => 'new'])
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenCacheFileDoesNotExist(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('missing', [])
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenCachePayloadIsNotObject(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($cacheDirectory . '/key.json', '["bad"]');

        try {
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', [])
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenViolationsPayloadIsMalformed(): void
    {
        $metadata            = ['configHash' => 'same'];
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        $this->writeCachePayload($cacheDirectory, [
            'metadata'   => $metadata,
            'violations' => 'bad',
        ]);

        try {
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', $metadata)
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenViolationEntryIsMalformed(): void
    {
        $metadata            = ['configHash' => 'same'];
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        $this->writeCachePayload($cacheDirectory, [
            'metadata'   => $metadata,
            'violations' => ['bad'],
        ]);

        try {
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', $metadata)
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenViolationEntryHasNumericKeys(): void
    {
        $metadata            = ['configHash' => 'same'];
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        $this->writeCachePayload($cacheDirectory, [
            'metadata'   => $metadata,
            'violations' => [['bad']],
        ]);

        try {
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', $metadata)
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenViolationEntryHasInvalidTypes(): void
    {
        $metadata            = ['configHash' => 'same'];
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        $this->writeCachePayload($cacheDirectory, [
            'metadata'   => $metadata,
            'violations' => [
                [
                    'rule'    => 'rule',
                    'message' => 'Nope',
                    'file'    => __FILE__,
                    'line'    => '10',
                    'class'   => self::class,
                    'layer'   => 'Domain',
                ],
            ],
        ]);

        try {
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', $metadata)
            );
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testDetectsDifferentConfigHashAndClearsCache(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $analysisResultCache->store('key', ['configHash' => 'old'], new RuleViolationCollection());

            $this->assertTrue($analysisResultCache->hasDifferentConfig('new'));

            $analysisResultCache->clear();

            $this->assertFalse(is_dir($cacheDirectory));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClearRemovesEmptyCacheSubdirectories(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        mkdir($cacheDirectory . '/nested');

        try {
            $analysisResultCache->clear();

            $this->assertFalse(is_dir($cacheDirectory));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testConfigHashIsNotDifferentWhenCacheDirectoryIsMissing(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        $this->removeTempDirectory($cacheDirectory);

        $this->assertFalse($analysisResultCache->hasDifferentConfig('new'));
    }

    public function testConfigHashIsNotDifferentWhenStoredHashMatches(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $analysisResultCache->store('key', ['configHash' => 'same'], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->hasDifferentConfig('same'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testConfigHashSkipsUnreadableCachePayloadsAndDirectories(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        mkdir($cacheDirectory . '/nested');
        file_put_contents($cacheDirectory . '/key.json', '["bad"]');
        $this->writeCachePayload($cacheDirectory, [
            'metadata'   => 'bad',
            'violations' => [],
        ], 'other.json');

        try {
            $this->assertFalse($analysisResultCache->hasDifferentConfig('same'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoreCreatesMissingCacheDirectory(): void
    {
        $basePath                = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache($basePath);
        $ruleViolationCollection = new RuleViolationCollection();

        try {
            $analysisResultCache->clear();
            $analysisResultCache->store('key', ['configHash' => 'same'], $ruleViolationCollection);

            $this->assertInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load('key', ['configHash' => 'same'])
            );
        } finally {
            $analysisResultCache->clear();
            $this->removeTempDirectory($basePath);
        }
    }

    public function testConfiguredRelativeCacheDirectoryIsResolvedFromBasePath(): void
    {
        $basePath            = $this->createTempDirectory();
        $cacheDirectory      = $basePath . '/var/cache/structarmed';
        $analysisResultCache = new AnalysisResultCache($basePath, 'var/cache/structarmed');

        try {
            mkdir($basePath . '/var');
            mkdir($basePath . '/var/cache');

            $analysisResultCache->store('key', ['configHash' => 'same'], new RuleViolationCollection());

            $this->assertTrue(file_exists($cacheDirectory . '/key.json'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);

            if (is_dir($basePath . '/var/cache')) {
                rmdir($basePath . '/var/cache');
            }

            if (is_dir($basePath . '/var')) {
                rmdir($basePath . '/var');
            }

            $this->removeTempDirectory($basePath);
        }
    }

    public function testConfiguredWindowsAbsoluteCacheDirectoryIsUsedAsIs(): void
    {
        $analysisResultCache = new AnalysisResultCache(__DIR__, 'C:/structarmed/cache');

        $this->assertFalse($analysisResultCache->hasDifferentConfig('same'));
    }

    public function testMetadataIncludesConfigAndAnalysedFiles(): void
    {
        $directory = $this->createTempDirectory();
        $config    = $directory . '/structarmed.php';
        $source    = $directory . '/Example.php';

        file_put_contents($config, '<?php return null;');
        file_put_contents($source, '<?php class Example {}');

        try {
            $metadata = (new AnalysisCacheMetadataFactory())->metadata(
                $directory,
                $config,
                ['src'],
                [$source]
            );

            $this->assertSame($directory, $metadata['basePath']);
            $this->assertSame($config, $metadata['configPath']);
            $this->assertSame(['src'], $metadata['scanPaths']);
            $this->assertIsString($metadata['configHash']);
            $this->assertIsString($metadata['filesHash']);
            $this->assertSame(
                (new AnalysisCacheMetadataFactory())->key($metadata),
                (new AnalysisCacheMetadataFactory())->key($metadata)
            );
        } finally {
            if (file_exists($config)) {
                unlink($config);
            }

            if (file_exists($source)) {
                unlink($source);
            }

            $this->removeTempDirectory($directory);
        }
    }

    private function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/structarmed-cache-test-' . bin2hex(random_bytes(6));
        mkdir($path);

        return $path;
    }

    private function removeTempDirectory(string $path): void
    {
        foreach (glob($path . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($path . '/nested')) {
            rmdir($path . '/nested');
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCachePayload(string $cacheDirectory, array $payload, string $filename = 'key.json'): void
    {
        file_put_contents($cacheDirectory . '/' . $filename, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
