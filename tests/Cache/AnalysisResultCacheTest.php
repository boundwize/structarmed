<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function filesize;
use function glob;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_starts_with;
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

    public function testConfigHashSkipsClassNodeCachePayloads(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);

            $this->assertFalse($analysisResultCache->hasDifferentConfig('same'));
        } finally {
            unlink($sourceFile);
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

    public function testStoresAndLoadsClassNodes(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);
        $classNodes          = [$this->makeClassNode($sourceFile)];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', $classNodes);

            $loaded = $analysisResultCache->loadClassNodes($sourceFile, 'config');

            $this->assertEquals($classNodes, $loaded);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoreClassNodesCreatesMissingCacheDirectory(): void
    {
        $basePath            = $this->createTempDirectory();
        $sourceFile          = $basePath . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache($basePath);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->clear();
            $analysisResultCache->storeClassNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);

            $this->assertInstanceOf(
                ClassNode::class,
                $analysisResultCache->loadClassNodes($sourceFile, 'config')[0] ?? null
            );
        } finally {
            $analysisResultCache->clear();
            unlink($sourceFile);
            $this->removeTempDirectory($basePath);
        }
    }

    public function testClassNodesMissWhenCacheFileDoesNotExist(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $this->assertNull($analysisResultCache->loadClassNodes($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesMissWhenFileMetadataChanges(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);
            file_put_contents($sourceFile, '<?php class Foo { public function changed(): void {} }');

            $this->assertNull($analysisResultCache->loadClassNodes($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedClassNodePayloadProvider(): iterable
    {
        yield 'nodes is not an array' => [['nodes' => 'bad']];
        yield 'node is not an array' => [['nodes' => ['bad']]];
        yield 'node has numeric keys' => [['nodes' => [['bad']]]];
        yield 'node has invalid scalar types' => [
            [
                'nodes' => [
                    [
                        'className'     => 10,
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => [],
                        'implements'    => [],
                        'methods'       => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'node has invalid string array key' => [
            [
                'nodes' => [
                    [
                        'className'     => 'App\Foo',
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => ['bad' => 'App\Bar'],
                        'implements'    => [],
                        'methods'       => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'node has non-array string array field' => [
            [
                'nodes' => [
                    [
                        'className'     => 'App\Foo',
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => 'bad',
                        'implements'    => [],
                        'methods'       => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'node has non-string list item' => [
            [
                'nodes' => [
                    [
                        'className'     => 'App\Foo',
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => [10],
                        'implements'    => [],
                        'methods'       => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'method is not an array' => [
            [
                'nodes' => [
                    [
                        'className'     => 'App\Foo',
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => [],
                        'implements'    => [],
                        'methods'       => ['bad'],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'method has numeric keys' => [
            [
                'nodes' => [
                    [
                        'className'     => 'App\Foo',
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => [],
                        'implements'    => [],
                        'methods'       => [['bad']],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'method has invalid types' => [
            [
                'nodes' => [
                    [
                        'className'     => 'App\Foo',
                        'file'          => __FILE__,
                        'line'          => 1,
                        'layer'         => null,
                        'extends'       => null,
                        'isAbstract'    => false,
                        'isFinal'       => true,
                        'isInterface'   => false,
                        'isReadonly'    => false,
                        'dependencies'  => [],
                        'implements'    => [],
                        'methods'       => [
                            [
                                'name'                 => 'run',
                                'visibility'           => 'public',
                                'hasReturnType'        => true,
                                'isStatic'             => false,
                                'paramCount'           => 0,
                                'cyclomaticComplexity' => 1,
                                'lineCount'            => 1,
                                'line'                 => 'bad',
                            ],
                        ],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payloadOverride
     */
    #[DataProvider('malformedClassNodePayloadProvider')]
    public function testClassNodesMissWhenPayloadIsMalformed(array $payloadOverride): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);
            $cacheFile = $this->firstJsonFile($cacheDirectory);

            $this->writeCachePayload($cacheDirectory, [
                'metadata' => [
                    'namespace' => 'config',
                    'file'      => $sourceFile,
                    'mtime'     => filemtime($sourceFile) ?: 0,
                    'size'      => filesize($sourceFile) ?: 0,
                ],
                ...$payloadOverride,
            ], $cacheFile);

            $this->assertNull($analysisResultCache->loadClassNodes($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
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

    private function makeClassNode(string $file): ClassNode
    {
        return new ClassNode(
            className:     'App\Foo',
            file:          $file,
            line:          1,
            layer:         'Source',
            extends:       null,
            isAbstract:    false,
            isFinal:       true,
            isInterface:   false,
            isReadonly:    false,
            dependencies:  ['App\Bar'],
            implements:    ['Stringable'],
            methods:       [
                new MethodNode(
                    name:                 'run',
                    visibility:           'public',
                    hasReturnType:        true,
                    isStatic:             false,
                    paramCount:           0,
                    cyclomaticComplexity: 1,
                    lineCount:            1,
                    line:                 1,
                ),
            ],
            functionCalls: ['sprintf'],
            superglobals:  ['_SERVER'],
        );
    }

    private function firstJsonFile(string $cacheDirectory): string
    {
        $files = glob($cacheDirectory . '/*.json') ?: [];
        $this->assertNotSame([], $files);

        return $files[0];
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
        $isAbsolute = str_starts_with($filename, '/')
            || str_starts_with($filename, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $filename) === 1;
        $path = $isAbsolute ? $filename : $cacheDirectory . '/' . $filename;

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
