<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function is_dir;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rmdir;
use function str_replace;
use function str_starts_with;
use function sys_get_temp_dir;
use function touch;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(AnalysisCacheMetadataFactory::class)]
#[CoversClass(AnalysisResultCache::class)]
final class AnalysisResultCacheTest extends TestCase
{
    public function testGetCacheDirectoryReturnsConfiguredDirectory(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $this->assertSame($cacheDirectory, $analysisResultCache->getCacheDirectory());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoresAndLoadsViolationCollection(): void
    {
        $cacheDirectory          = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache(__DIR__, $cacheDirectory);
        $metadata                = ['configHash' => 'same', 'filesHash' => 'same'];
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add(new RuleViolation(
            message:   'Nope',
            file:      __FILE__,
            line:      10,
            className: self::class,
            layer:     'Domain',
            ruleKey:   'rule',
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

            $this->assertDirectoryDoesNotExist($cacheDirectory);
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

            $this->assertDirectoryDoesNotExist($cacheDirectory);
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

    public function testComposerGeneratedVersionHashIsNotDifferentWhenCacheDirectoryIsMissing(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        $this->removeTempDirectory($cacheDirectory);

        $this->assertFalse($analysisResultCache->hasDifferentComposerGeneratedVersion('new'));
    }

    public function testComposerGeneratedVersionHashIsNotDifferentWhenNoCachedEntryHasTheField(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $analysisResultCache->store('key', ['configHash' => 'same'], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->hasDifferentComposerGeneratedVersion('some-hash'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testComposerGeneratedVersionHashIsNotDifferentWhenStoredHashMatches(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $analysisResultCache->store(
                'key',
                ['composerGeneratedVersionHash' => 'same'],
                new RuleViolationCollection()
            );

            $this->assertFalse($analysisResultCache->hasDifferentComposerGeneratedVersion('same'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testComposerGeneratedVersionHashIsDifferentWhenStoredHashDiffers(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        try {
            $analysisResultCache->store(
                'key',
                ['composerGeneratedVersionHash' => 'old'],
                new RuleViolationCollection()
            );

            $this->assertTrue($analysisResultCache->hasDifferentComposerGeneratedVersion('new'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testComposerGeneratedVersionHashSkipsUnreadableCachePayloadsAndDirectories(): void
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
            $this->assertFalse($analysisResultCache->hasDifferentComposerGeneratedVersion('some-hash'));
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testComposerGeneratedVersionHashSkipsClassNodeCachePayloads(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);

            $this->assertFalse($analysisResultCache->hasDifferentComposerGeneratedVersion('some-hash'));
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

            $this->assertFileExists($cacheDirectory . '/key.json');
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

    public function testClassNodesPreserveTraitFlag(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/FooTrait.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php trait FooTrait {}');

        $classNodes = [
            new ClassNode(
                className:   'App\FooTrait',
                file:        $sourceFile,
                line:        1,
                layer:       'Source',
                extends:     null,
                isAbstract:  false,
                isFinal:     false,
                isInterface: false,
                isReadonly:  false,
                isTrait:     true,
            ),
        ];

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadClassNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertTrue($loaded[0]->isTrait);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesPreserveMethodExplicitVisibility(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        $classNodes = [
            new ClassNode(
                className:   'App\Foo',
                file:        $sourceFile,
                line:        1,
                layer:       'Source',
                extends:     null,
                isAbstract:  false,
                isFinal:     true,
                isInterface: false,
                isReadonly:  false,
                methods:     [
                    new MethodNode(
                        name:                 'setUp',
                        visibility:           'protected',
                        hasReturnType:        true,
                        isStatic:             false,
                        paramCount:           0,
                        cyclomaticComplexity: 1,
                        lineCount:            3,
                        hasExplicitVisibility: true,
                        line:                 10,
                    ),
                ],
            ),
        ];

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadClassNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded);
            $this->assertTrue($loaded[0]->methods[0]->hasExplicitVisibility);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesPreserveConstants(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        $classNodes = [
            new ClassNode(
                className:   'App\Foo',
                file:        $sourceFile,
                line:        1,
                layer:       'Source',
                extends:     null,
                isAbstract:  false,
                isFinal:     true,
                isInterface: false,
                isReadonly:  false,
                constants:   [
                    new ConstantNode(name: 'VERSION', visibility: 'public', hasExplicitVisibility: true, line: 5),
                    new ConstantNode(name: 'LEGACY', visibility: 'public', hasExplicitVisibility: false, line: 6),
                ],
            ),
        ];

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadClassNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded);
            $this->assertTrue($loaded[0]->constants[0]->hasExplicitVisibility);
            $this->assertFalse($loaded[0]->constants[1]->hasExplicitVisibility);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesPreserveProperties(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        $classNodes = [
            new ClassNode(
                className:   'App\Foo',
                file:        $sourceFile,
                line:        1,
                layer:       'Source',
                extends:     null,
                isAbstract:  false,
                isFinal:     true,
                isInterface: false,
                isReadonly:  false,
                properties:  [
                    new PropertyNode(name: 'name', visibility: 'private', hasExplicitVisibility: true, line: 8),
                    new PropertyNode(name: 'legacy', visibility: 'public', hasExplicitVisibility: false, line: 9),
                ],
            ),
        ];

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadClassNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded);
            $this->assertTrue($loaded[0]->properties[0]->hasExplicitVisibility);
            $this->assertFalse($loaded[0]->properties[1]->hasExplicitVisibility);
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

    public function testClassNodesHitWhenOnlyFileMtimeChanges(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, $cacheDirectory);
        $classNodes          = [$this->makeClassNode($sourceFile)];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeClassNodes($sourceFile, 'config', $classNodes);
            touch($sourceFile, 1234567890);

            $this->assertEquals($classNodes, $analysisResultCache->loadClassNodes($sourceFile, 'config'));
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
        yield 'node has missing isTrait field' => [
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
                        // 'isTrait' intentionally absent — simulates an old cache entry
                        'dependencies'  => [],
                        'implements'    => [],
                        'traits'        => [],
                        'methods'       => [],
                        'constants'     => [],
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                        'layers'        => [],
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
                        'constants'     => [],
                        'properties'    => [],
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
                        'constants'     => [],
                        'properties'    => [],
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
                                'name'                  => 'run',
                                'visibility'            => 'public',
                                'hasReturnType'         => true,
                                'isStatic'              => false,
                                'paramCount'            => 0,
                                'cyclomaticComplexity'  => 1,
                                'lineCount'             => 1,
                                'hasExplicitVisibility' => true,
                                'line'                  => 'bad',
                            ],
                        ],
                        'constants'     => [],
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'method has missing hasExplicitVisibility' => [
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
                                'line'                 => 1,
                            ],
                        ],
                        'constants'     => [],
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'constants is not an array' => [
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
                        'methods'       => [],
                        'constants'     => 'bad',
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'constant is not an array' => [
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
                        'methods'       => [],
                        'constants'     => ['bad'],
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'constant has numeric keys' => [
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
                        'methods'       => [],
                        'constants'     => [['bad']],
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'constant has invalid types' => [
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
                        'methods'       => [],
                        'constants'     => [
                            [
                                'name'                  => 'VERSION',
                                'visibility'            => 'public',
                                'hasExplicitVisibility' => true,
                                'line'                  => 'bad',
                            ],
                        ],
                        'properties'    => [],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'properties is not an array' => [
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
                        'methods'       => [],
                        'constants'     => [],
                        'properties'    => 'bad',
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'property is not an array' => [
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
                        'methods'       => [],
                        'constants'     => [],
                        'properties'    => ['bad'],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'property has numeric keys' => [
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
                        'methods'       => [],
                        'constants'     => [],
                        'properties'    => [['bad']],
                        'functionCalls' => [],
                        'superglobals'  => [],
                    ],
                ],
            ],
        ];
        yield 'property has invalid types' => [
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
                        'methods'       => [],
                        'constants'     => [],
                        'properties'    => [
                            [
                                'name'                  => 'name',
                                'visibility'            => 'private',
                                'hasExplicitVisibility' => true,
                                'line'                  => 'bad',
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
                    'hash'      => hash('xxh128', (string) file_get_contents($sourceFile)),
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
            $this->assertIsString($metadata['composerGeneratedVersionHash']);
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

    public function testMetadataIncludesComposerGeneratedVersionHash(): void
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

            $this->assertIsString($metadata['composerGeneratedVersionHash']);
        } finally {
            foreach ([$config, $source] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $this->removeTempDirectory($directory);
        }
    }

    public function testComposerGeneratedVersionHashUsesRootPackageWhenStructarmedIsNotInstalled(): void
    {
        $canGetVendors = new ReflectionProperty(InstalledVersions::class, 'canGetVendors');
        $installed     = new ReflectionProperty(InstalledVersions::class, 'installed');
        $isLocalDir    = new ReflectionProperty(InstalledVersions::class, 'installedIsLocalDir');

        $origCanGetVendors = $canGetVendors->getValue();
        $origInstalled     = $installed->getValue();
        $origIsLocalDir    = $isLocalDir->getValue();

        try {
            $canGetVendors->setValue(null, false);
            InstalledVersions::reload([
                'root'     => [
                    'name'           => 'some/project',
                    'pretty_version' => 'dev-main',
                    'version'        => 'dev-main',
                    'reference'      => null,
                    'type'           => 'project',
                    'install_path'   => __DIR__,
                    'aliases'        => [],
                    'dev'            => true,
                ],
                'versions' => [],
            ]);

            $this->assertSame(
                hash('xxh128', json_encode(InstalledVersions::getRootPackage(), JSON_THROW_ON_ERROR)),
                (new AnalysisCacheMetadataFactory())->composerGeneratedVersionHash()
            );
        } finally {
            $installed->setValue(null, $origInstalled);
            $isLocalDir->setValue(null, $origIsLocalDir);
            $canGetVendors->setValue(null, $origCanGetVendors);
        }
    }

    public function testMetadataFileHashDoesNotChangeWhenOnlyFileMtimeChanges(): void
    {
        $directory = $this->createTempDirectory();
        $config    = $directory . '/structarmed.php';
        $source    = $directory . '/Example.php';

        file_put_contents($config, '<?php return null;');
        file_put_contents($source, '<?php class Example {}');

        try {
            $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
            $metadataBefore               = $analysisCacheMetadataFactory->metadata(
                $directory,
                $config,
                ['src'],
                [$source]
            );

            touch($source, 1234567890);

            $metadataAfter = $analysisCacheMetadataFactory->metadata(
                $directory,
                $config,
                ['src'],
                [$source]
            );

            $this->assertSame($metadataBefore['filesHash'], $metadataAfter['filesHash']);
        } finally {
            foreach ([$config, $source] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $this->removeTempDirectory($directory);
        }
    }

    public function testCacheMissesWhenComposerGeneratedVersionChanges(): void
    {
        $directory = $this->createTempDirectory();
        $config    = $directory . '/structarmed.php';
        $source    = $directory . '/Example.php';
        $cacheDir  = $this->createTempDirectory();

        file_put_contents($config, '<?php return null;');
        file_put_contents($source, '<?php class Example {}');

        try {
            $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
            $analysisResultCache          = new AnalysisResultCache($directory, $cacheDir);
            $metadataBefore               = $analysisCacheMetadataFactory->metadata(
                $directory,
                $config,
                ['src'],
                [$source]
            );
            $key                          = $analysisCacheMetadataFactory->key($metadataBefore);

            $analysisResultCache->store($key, $metadataBefore, new RuleViolationCollection());

            $this->assertInstanceOf(RuleViolationCollection::class, $analysisResultCache->load($key, $metadataBefore));

            $metadataAfter                                 = $analysisCacheMetadataFactory->metadata(
                $directory,
                $config,
                ['src'],
                [$source]
            );
            $metadataAfter['composerGeneratedVersionHash'] = 'changed';

            $this->assertNotSame($key, $analysisCacheMetadataFactory->key($metadataAfter));
            $this->assertNotInstanceOf(
                RuleViolationCollection::class,
                $analysisResultCache->load($key, $metadataAfter)
            );
        } finally {
            foreach ([$config, $source] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $this->removeTempDirectory($directory);
            $this->removeTempDirectory($cacheDir);
        }
    }

    private function createTempDirectory(): string
    {
        $path = str_replace('\\', '/', sys_get_temp_dir()) . '/structarmed-cache-test-' . bin2hex(random_bytes(6));
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
                    hasExplicitVisibility: true,
                    line:                 1,
                ),
            ],
            constants:     [
                new ConstantNode(name: 'VERSION', visibility: 'public', hasExplicitVisibility: true, line: 3),
            ],
            properties:    [
                new PropertyNode(name: 'name', visibility: 'private', hasExplicitVisibility: true, line: 5),
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
        $path       = $isAbsolute ? $filename : $cacheDirectory . '/' . $filename;

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
