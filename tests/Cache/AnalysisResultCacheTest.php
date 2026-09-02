<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use App\Foo;
use ArrayObject;
use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Analyser\ExtractionResult;
use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Composer\InstalledVersions;
use Iterator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_column;
use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function hash_file;
use function is_dir;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rmdir;
use function serialize;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function touch;
use function unlink;
use function unserialize;

use const JSON_THROW_ON_ERROR;

#[CoversClass(AnalysisCacheMetadataFactory::class)]
#[CoversClass(AnalysisResultCache::class)]
final class AnalysisResultCacheTest extends TestCase
{
    public function testGetCacheDirectoryReturnsConfiguredDirectory(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        try {
            $this->assertSame($cacheDirectory, $analysisResultCache->getCacheDirectory());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoresAndLoadsViolationCollection(): void
    {
        $cacheDirectory          = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $metadata                = ['configHash' => 'same', 'filesHash' => 'same'];
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add(new RuleViolation(
            message:   'Nope',
            file:      __FILE__,
            line:      10,
            className: self::class,
            layer:     'Domain',
            ruleKey:   'rule',
            fixable:   true,
            methodName: 'save',
            constantName: 'VERSION',
            propertyName: 'status',
        ));

        try {
            $analysisResultCache->store('key', $metadata, $ruleViolationCollection);
            $loaded = $analysisResultCache->load('key', $metadata);

            $this->assertSame(
                json_encode([
                    'metadata'   => $metadata,
                    'violations' => $ruleViolationCollection->toArray(),
                ], JSON_THROW_ON_ERROR),
                file_get_contents($cacheDirectory . '/key.json')
            );
            $this->assertInstanceOf(RuleViolationCollection::class, $loaded);
            $this->assertSame($ruleViolationCollection->toArray(), $loaded->toArray());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoresViolationCollectionWithInvalidUtf8Text(): void
    {
        $cacheDirectory          = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $metadata                = ['configHash' => 'same', 'filesHash' => 'same'];
        $ruleViolationCollection = new RuleViolationCollection();
        $ruleViolationCollection->add(new RuleViolation(
            message:   "Invalid byte \xB1",
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
            $loadedViolations = $loaded->toArray();

            $this->assertIsString($loadedViolations[0]['message']);
            $this->assertStringContainsString("\xEF\xBF\xBD", $loadedViolations[0]['message']);
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testMissesWhenMetadataChanges(): void
    {
        $cacheDirectory          = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
        $staleCache          = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'old',
            'composer-hash',
        );
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'new',
            'composer-hash',
        );

        try {
            $staleCache->store('key', ['configHash' => 'old'], new RuleViolationCollection());

            $this->assertTrue($analysisResultCache->shouldInvalidate());

            $analysisResultCache->clear();

            $this->assertDirectoryDoesNotExist($cacheDirectory);

            $analysisResultCache->store('key', ['configHash' => 'new'], new RuleViolationCollection());

            $this->assertDirectoryExists($cacheDirectory);
            $this->assertFalse($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClearRemovesEmptyCacheSubdirectories(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        mkdir($cacheDirectory . '/nested');

        try {
            $analysisResultCache->clear();

            $this->assertDirectoryDoesNotExist($cacheDirectory);
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testCacheMetadataIsNotDifferentWhenCacheDirectoryIsMissing(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        $this->removeTempDirectory($cacheDirectory);

        $this->assertFalse($analysisResultCache->shouldInvalidate());
    }

    public function testConfigHashIsNotDifferentWhenStoredHashMatches(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'same',
            'composer-hash',
        );

        try {
            $analysisResultCache->store('key', ['configHash' => 'same'], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testInvalidationIgnoresUnreadableCachePayloadsAndDirectories(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'same',
            'composer-hash',
        );

        mkdir($cacheDirectory . '/nested');
        file_put_contents($cacheDirectory . '/key.json', '["bad"]');
        $this->writeCachePayload($cacheDirectory, [
            'metadata'   => 'bad',
            'violations' => [],
        ], 'other.json');

        try {
            $analysisResultCache->store('valid', ['configHash' => 'same'], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testInvalidationIgnoresStoredPayloadMetadata(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'same',
            'composer-hash',
        );

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);
            $analysisResultCache->store('key', ['configHash' => 'other'], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->shouldInvalidate());
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testCacheFromOlderFormatVersionIsInvalidated(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'same',
            'composer-hash',
        );

        try {
            // A marker written by a release before the format version was
            // recorded, or by an older format version, with otherwise
            // matching hashes.
            file_put_contents($cacheDirectory . '/_metadata.json', json_encode([
                'configHash'                   => 'same',
                'composerGeneratedVersionHash' => 'composer-hash',
            ], JSON_THROW_ON_ERROR));

            $this->assertTrue($analysisResultCache->shouldInvalidate());

            file_put_contents($cacheDirectory . '/_metadata.json', json_encode([
                'version'                      => AnalysisResultCache::FORMAT_VERSION - 1,
                'configHash'                   => 'same',
                'composerGeneratedVersionHash' => 'composer-hash',
            ], JSON_THROW_ON_ERROR));

            $this->assertTrue($analysisResultCache->shouldInvalidate());

            $analysisResultCache->clear();
            $analysisResultCache->store('key', [], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testCacheIsInvalidatedWhenComposerJsonChanges(): void
    {
        $basePath       = $this->createTempDirectory();
        $cacheDirectory = $this->createTempDirectory();
        file_put_contents($basePath . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');

        try {
            $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), $cacheDirectory);
            $analysisResultCache->store('key', [], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->shouldInvalidate());

            file_put_contents($basePath . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "lib/"}}}');

            $this->assertTrue(
                (new AnalysisResultCache($basePath, new FileHashProvider(), $cacheDirectory))->shouldInvalidate()
            );
        } finally {
            $this->removeTempDirectory($basePath);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testPopulatedCacheWithoutMetadataMarkerIsInvalidated(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'same',
            'composer-hash',
        );

        try {
            $this->writeCachePayload($cacheDirectory, [
                'metadata'   => ['configHash' => 'same'],
                'violations' => [],
            ]);

            $this->assertTrue($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testComposerGeneratedVersionHashIsNotDifferentWhenStoredHashMatches(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'config-hash',
            'same',
        );

        try {
            $analysisResultCache->store('key', [], new RuleViolationCollection());

            $this->assertFalse($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testComposerGeneratedVersionHashIsDifferentWhenStoredHashDiffers(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $staleCache          = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'config-hash',
            'old',
        );
        $analysisResultCache = new AnalysisResultCache(
            __DIR__,
            new FileHashProvider(),
            $cacheDirectory,
            'config-hash',
            'new',
        );

        try {
            $staleCache->store('key', [], new RuleViolationCollection());

            $this->assertTrue($analysisResultCache->shouldInvalidate());
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodeCacheNamespaceDependsOnConfigAndComposerJson(): void
    {
        $basePath                     = $this->createTempDirectory();
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory(new FileHashProvider());

        try {
            $withoutComposer = $analysisCacheMetadataFactory->analysisNodeCacheNamespace($basePath, 'config-hash');

            $this->assertSame(
                $withoutComposer,
                $analysisCacheMetadataFactory->analysisNodeCacheNamespace($basePath, 'config-hash')
            );
            $this->assertNotSame(
                $withoutComposer,
                $analysisCacheMetadataFactory->analysisNodeCacheNamespace($basePath, 'other-config-hash')
            );

            file_put_contents($basePath . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"lib/"}}}');
            $withComposer = $analysisCacheMetadataFactory->analysisNodeCacheNamespace($basePath, 'config-hash');

            $this->assertNotSame($withoutComposer, $withComposer);

            file_put_contents($basePath . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');

            $nextRunMetadataFactory = new AnalysisCacheMetadataFactory(new FileHashProvider());

            $this->assertNotSame(
                $withComposer,
                $nextRunMetadataFactory->analysisNodeCacheNamespace($basePath, 'config-hash')
            );
        } finally {
            $this->removeTempDirectory($basePath);
        }
    }

    public function testStoreExtractionResultStoresOnePayloadPerFile(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $fileWithNodes       = __FILE__;
        $fileWithoutNodes    = __DIR__ . '/FileHashProviderTest.php';

        try {
            $analysisResultCache->storeExtractionResult(
                [$fileWithNodes, $fileWithoutNodes],
                'namespace',
                new ExtractionResult(
                    classNodes: [$this->makeClassNode($fileWithNodes)],
                    fileAnalyses: [],
                    anonymousClassNodes: [new AnonymousClassNode(file: $fileWithNodes, line: 7, extends: null)],
                    functionNodes: [
                        new FunctionNode(
                            functionName:         'App\\format',
                            file:                 $fileWithNodes,
                            line:                 3,
                            layer:                'Source',
                            hasReturnType:        true,
                            paramCount:           0,
                            cyclomaticComplexity: 1,
                            lineCount:            1,
                        ),
                    ],
                    anonymousFunctionNodes: [
                        new AnonymousFunctionNode(
                            file:                  $fileWithNodes,
                            line:                  5,
                            layer:                 null,
                            isArrowFunction:       true,
                            isStatic:              true,
                            enclosingFunctionName: 'App\\format',
                            usesThis:              false,
                            hasReturnType:         false,
                            paramCount:            0,
                            cyclomaticComplexity:  1,
                            lineCount:             1,
                        ),
                    ],
                )
            );

            $withNodes    = $analysisResultCache->loadAnalysisNodes($fileWithNodes, 'namespace');
            $withoutNodes = $analysisResultCache->loadAnalysisNodes($fileWithoutNodes, 'namespace');

            $this->assertNotNull($withNodes);
            $this->assertCount(1, $withNodes['classNodes']);
            $this->assertCount(1, $withNodes['anonymousClassNodes']);
            $this->assertCount(1, $withNodes['functionNodes']);
            $this->assertCount(1, $withNodes['anonymousFunctionNodes']);
            $this->assertNotNull($withoutNodes);
            $this->assertSame([], $withoutNodes['classNodes']);
            $this->assertSame([], $withoutNodes['functionNodes']);
        } finally {
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoreCreatesMissingCacheDirectory(): void
    {
        $basePath                = $this->createTempDirectory();
        $analysisResultCache     = new AnalysisResultCache($basePath, new FileHashProvider());
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
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider(), 'var/cache/structarmed');

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), 'C:/structarmed/cache');

        $this->assertFalse($analysisResultCache->shouldInvalidate());
    }

    public function testStoresAndLoadsClassNodes(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $classNodes          = [$this->makeClassNode($sourceFile)];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);

            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

            $this->assertEquals($classNodes, $loaded);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoresAndLoadsAnonymousClassNodes(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $classNodes          = [$this->makeClassNode($sourceFile)];
        $anonymousClassNodes = [
            new AnonymousClassNode(
                file:       $sourceFile,
                line:       7,
                extends:    'App\BaseHandler',
                implements: ['App\Contract'],
                traits:     ['App\Helper'],
            ),
        ];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes(
                $sourceFile,
                'config',
                $classNodes,
                null,
                $anonymousClassNodes,
                ['App\ReferencedInFunction'],
                ['App\InstantiatedInFunction'],
            );

            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded['classNodes']);
            $this->assertEquals($anonymousClassNodes, $loaded['anonymousClassNodes']);
            $this->assertSame(['App\ReferencedInFunction'], $loaded['fileReferences']);
            $this->assertSame(['App\InstantiatedInFunction'], $loaded['fileInstantiations']);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoresAndLoadsFunctionLikeNodes(): void
    {
        $cacheDirectory         = $this->createTempDirectory();
        $sourceFile             = $cacheDirectory . '/helpers.php';
        $analysisResultCache    = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $functionNodes          = [
            new FunctionNode(
                functionName:         'App\\format',
                file:                 $sourceFile,
                line:                 3,
                layer:                'Source',
                hasReturnType:        true,
                paramCount:           2,
                cyclomaticComplexity: 4,
                lineCount:            9,
                dependencies:         ['App\\Money'],
                functionCalls:        ['sprintf'],
                superglobals:         ['$_GET'],
                languageConstructs:   ['echo'],
                layers:               ['Source', 'Support'],
            ),
        ];
        $anonymousFunctionNodes = [
            new AnonymousFunctionNode(
                file:                  $sourceFile,
                line:                  5,
                layer:                 null,
                isArrowFunction:       true,
                isStatic:              true,
                enclosingClassName:    'App\\Handler',
                enclosingFunctionName: 'App\\format',
                usesThis:              true,
                hasReturnType:         false,
                paramCount:            1,
                cyclomaticComplexity:  2,
                lineCount:             1,
                dependencies:          ['App\\Money'],
                functionCalls:         ['App\\helper'],
                superglobals:          [],
                languageConstructs:    ['exit'],
            ),
        ];

        file_put_contents($sourceFile, '<?php function format() {}');

        try {
            $analysisResultCache->storeAnalysisNodes(
                $sourceFile,
                'config',
                [],
                null,
                [],
                [],
                [],
                $functionNodes,
                $anonymousFunctionNodes,
            );

            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertEquals($functionNodes, $loaded['functionNodes']);
            $this->assertEquals($anonymousFunctionNodes, $loaded['anonymousFunctionNodes']);

            // Function-likes also survive the file-analysis load path.
            $analysisResultCache->storeAnalysisNodes(
                $sourceFile,
                'config',
                [],
                new FileAnalysis($sourceFile, false, true, null, true, true, false, 0),
                [],
                [],
                [],
                $functionNodes,
                $anonymousFunctionNodes,
            );

            $loadedWithFileAnalysis = $analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config');

            $this->assertIsArray($loadedWithFileAnalysis);
            $this->assertEquals($functionNodes, $loadedWithFileAnalysis['functionNodes']);
            $this->assertEquals($anonymousFunctionNodes, $loadedWithFileAnalysis['anonymousFunctionNodes']);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testFilesWithoutFunctionLikesOmitTheirKeysAndLoadAsEmpty(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $fileHashProvider    = new FileHashProvider();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $fileHashProvider, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', []);

            $payload = unserialize((string) file_get_contents($this->firstNodeFile($cacheDirectory)));

            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('functionNodes', $payload);
            $this->assertArrayNotHasKey('anonymousFunctionNodes', $payload);
            $this->assertArrayNotHasKey('fileAnalysis', $payload);

            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertSame([], $loaded['functionNodes']);
            $this->assertSame([], $loaded['anonymousFunctionNodes']);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testPayloadWithoutOptionalListsLoadsThemAsEmpty(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $classNodes          = [$this->makeClassNode($sourceFile)];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $this->writeNodePayload($cacheDirectory, $sourceFile, 'config', [
                'metadata'   => $this->nodeMetadata($sourceFile, 'config'),
                'classNodes' => $classNodes,
            ]);

            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config');

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded['classNodes']);
            $this->assertSame([], $loaded['anonymousClassNodes']);
            $this->assertSame([], $loaded['fileReferences']);
            $this->assertSame([], $loaded['fileInstantiations']);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('malformedNodeListProvider')]
    public function testLoadAnalysisNodesMissesWhenANodeListIsMalformed(array $override): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $this->writeNodePayload($cacheDirectory, $sourceFile, 'config', $override + [
                'metadata'     => $this->nodeMetadata($sourceFile, 'config'),
                'classNodes'   => [$this->makeClassNode($sourceFile)],
                'fileAnalysis' => new FileAnalysis($sourceFile, false, true, null, true, true, false, 1),
            ]);

            $this->assertNull($analysisResultCache->loadAnalysisNodes($sourceFile, 'config'));
            $this->assertNull($analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    /** @return Iterator<string, array{0: array<string, mixed>}> */
    public static function malformedNodeListProvider(): Iterator
    {
        yield 'class nodes not an array' => [['classNodes' => 'bad']];
        yield 'class node entry not a node' => [['classNodes' => ['bad']]];
        yield 'class node entry of an unlisted class' => [['classNodes' => [new ArrayObject()]]];
        yield 'anonymous class nodes not an array' => [['anonymousClassNodes' => 'bad']];
        yield 'anonymous class node entry of another node kind' => [
            ['anonymousClassNodes' => [new FileAnalysis('/Foo.php', false, true, null, true, true, false, 1)]],
        ];
        yield 'class nodes not a list' => [
            ['classNodes' => [Foo::class => new AnonymousClassNode('/Foo.php', 1, null)]],
        ];
        yield 'file references not an array' => [['fileReferences' => 'bad']];
        yield 'file references entry not a string' => [['fileReferences' => [123]]];
        yield 'file references not a list' => [['fileReferences' => ['App\\Bar' => 'App\\Bar']]];
        yield 'file instantiations not an array' => [['fileInstantiations' => 'bad']];
        yield 'file instantiations entry not a string' => [['fileInstantiations' => [new ArrayObject()]]];
        yield 'function nodes not an array' => [['functionNodes' => 'bad']];
        yield 'function node entry of another node kind' => [
            ['functionNodes' => [new AnonymousClassNode(file: '/Foo.php', line: 1, extends: null)]],
        ];
        yield 'anonymous function nodes not an array' => [['anonymousFunctionNodes' => 'bad']];
        yield 'anonymous function node entry not a node' => [['anonymousFunctionNodes' => [['line' => 1]]]];
    }

    /**
     * @param callable(string): string $corrupt
     */
    #[DataProvider('unreadablePayloadProvider')]
    public function testLoadAnalysisNodesMissesWhenPayloadIsUnreadable(callable $corrupt): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes(
                $sourceFile,
                'config',
                [$this->makeClassNode($sourceFile)],
                new FileAnalysis($sourceFile, false, true, null, true, true, false, 1),
            );

            $cacheFile = $this->firstNodeFile($cacheDirectory);
            file_put_contents($cacheFile, $corrupt((string) file_get_contents($cacheFile)));

            $this->assertNull($analysisResultCache->loadAnalysisNodes($sourceFile, 'config'));
            $this->assertNull($analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    /** @return Iterator<string, array{0: callable(string): string}> */
    public static function unreadablePayloadProvider(): Iterator
    {
        yield 'empty file' => [static fn (string $payload): string => ''];
        yield 'garbage' => [static fn (string $payload): string => 'not a serialized payload'];
        yield 'truncated' => [static fn (string $payload): string => substr($payload, 0, (int) (strlen($payload) / 2))];
        yield 'not an array' => [static fn (string $payload): string => serialize('payload')];
        yield 'wrong-typed node property' => [
            // ClassNode::$line is a typed int; unserialize() throws a TypeError for the string.
            static fn (string $payload): string => str_replace('s:4:"line";i:1;', 's:4:"line";s:1:"1";', $payload),
        ];
    }

    /**
     * @param callable(ClassNode): void $mutate
     */
    #[DataProvider('postAnalysisStateProvider')]
    public function testStoringClassNodeWithPostAnalysisStateThrows(callable $mutate): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $classNode           = $this->makeClassNode($sourceFile);

        file_put_contents($sourceFile, '<?php class Foo {}');
        $mutate($classNode);

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('ClassNode [App\\Foo] carries post-analysis state and cannot be cached.');

            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', [$classNode]);
        } finally {
            $this->assertSame([], glob($cacheDirectory . '/*.bin') ?: []);
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    /** @return Iterator<string, array{0: callable(ClassNode): void}> */
    public static function postAnalysisStateProvider(): Iterator
    {
        yield 'parent classes' => [
            static fn (ClassNode $classNode) => $classNode->setRecursiveParents(['App\\Base'], []),
        ];
        yield 'parent interfaces' => [
            static fn (ClassNode $classNode) => $classNode->setRecursiveParents([], ['Stringable']),
        ];
        yield 'extended' => [static fn (ClassNode $classNode) => $classNode->setExtended(true)];
        yield 'implemented' => [static fn (ClassNode $classNode) => $classNode->setImplemented(true)];
        yield 'referenced' => [static fn (ClassNode $classNode) => $classNode->setReferenced(true)];
        yield 'instantiated' => [static fn (ClassNode $classNode) => $classNode->setInstantiated(true)];
    }

    public function testStoresClassNodesWithInvalidUtf8Text(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $classNodes          = [
            new ClassNode(
                className:   "App\\Invalid\xB1Name",
                file:        $sourceFile,
                line:        1,
                layer:       'Source',
                extends:     null,
                isAbstract:  false,
                isFinal:     true,
                isInterface: false,
                isReadonly:  false,
            ),
        ];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

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
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

            $this->assertIsArray($loaded);
            $this->assertTrue($loaded[0]->isTrait);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesPreserveEnumFlag(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Status.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php enum Status: string { case Draft = "draft"; }');

        $classNodes = [
            new ClassNode(
                className:   'App\Status',
                file:        $sourceFile,
                line:        1,
                layer:       'Source',
                extends:     null,
                isAbstract:  false,
                isFinal:     false,
                isInterface: false,
                isReadonly:  false,
                isEnum:      true,
            ),
        ];

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

            $this->assertIsArray($loaded);
            $this->assertTrue($loaded[0]->isEnum);
            $this->assertFalse($loaded[0]->isClass());
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesPreserveInterfaceExtends(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Middleware.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php interface Middleware extends BaseMiddleware {}');

        $classNodes = [
            new ClassNode(
                className:        'App\Middleware',
                file:             $sourceFile,
                line:             1,
                layer:            'Source',
                extends:          null,
                isAbstract:       false,
                isFinal:          false,
                isInterface:      true,
                isReadonly:       false,
                interfaceExtends: ['App\BaseMiddleware'],
            ),
        ];

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

            $this->assertIsArray($loaded);
            $this->assertSame(['App\BaseMiddleware'], $loaded[0]->interfaceExtends);
            $this->assertEquals($classNodes, $loaded);
        } finally {
            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }

            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesPreserveMethodMetadata(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        $classNodes = [
            new ClassNode(
                className:   Foo::class,
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
                        name:                 '__invoke',
                        visibility:           'public',
                        hasReturnType:        true,
                        isStatic:             false,
                        paramCount:           0,
                        cyclomaticComplexity: 1,
                        lineCount:            3,
                        hasExplicitVisibility: true,
                        line:                 10,
                        isMagic:              true,
                    ),
                ],
            ),
        ];

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded);
            $this->assertTrue($loaded[0]->methods[0]->hasExplicitVisibility);
            $this->assertTrue($loaded[0]->methods[0]->isMagic);
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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        $classNodes = [
            new ClassNode(
                className:   Foo::class,
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
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        $classNodes = [
            new ClassNode(
                className:   Foo::class,
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
                isEnum:      true,
                enumCases:   [
                    new EnumCaseNode(name: 'Hearts', line: 4, value: 'H'),
                    new EnumCaseNode(name: 'Spades', line: 5, value: 7),
                    new EnumCaseNode(name: 'Joker', line: 6),
                ],
                enumBackingType: 'string',
            ),
        ];

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            $loaded = $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null;

            $this->assertIsArray($loaded);
            $this->assertEquals($classNodes, $loaded);
            $this->assertTrue($loaded[0]->properties[0]->hasExplicitVisibility);
            $this->assertFalse($loaded[0]->properties[1]->hasExplicitVisibility);
            $this->assertSame(['Hearts', 'Spades', 'Joker'], array_column($loaded[0]->enumCases, 'name'));
            $this->assertSame(['H', 7, null], array_column($loaded[0]->enumCases, 'value'));
            $this->assertSame('string', $loaded[0]->enumBackingType);
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
        $analysisResultCache = new AnalysisResultCache($basePath, new FileHashProvider());

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->clear();
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);

            $this->assertInstanceOf(
                ClassNode::class,
                $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'][0] ?? null
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
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $this->assertNull($analysisResultCache->loadAnalysisNodes($sourceFile, 'config'));
            $this->assertNull($analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testStoresAndLoadsClassNodesWithFileAnalysis(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $fileAnalysis        = new FileAnalysis(
            file: $sourceFile,
            hasUtf8Bom: false,
            hasValidUtf8: true,
            invalidPhpTagLine: null,
            hasValidAst: true,
            declaresSymbols: true,
            hasSideEffects: false,
            sideEffectLine: 1,
            nonCanonicalKeywordConstants: [[3, 'TRUE'], [5, '\\NULL']],
        );

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $classNodes = [$this->makeClassNode($sourceFile)];
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes, $fileAnalysis);

            $loaded = $analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config');

            $this->assertNotNull($loaded);
            $this->assertEquals($classNodes, $loaded['classNodes']);
            $this->assertEquals($fileAnalysis, $loaded['fileAnalysis']);
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesWithFileAnalysisMissesLegacyEntryWithoutFileFacts(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);

            $this->assertNull($analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesWithFileAnalysisMissesWhenFactsAreNotAFileAnalysis(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $this->writeNodePayload($cacheDirectory, $sourceFile, 'config', [
                'metadata'     => $this->nodeMetadata($sourceFile, 'config'),
                'classNodes'   => [$this->makeClassNode($sourceFile)],
                'fileAnalysis' => ['file' => $sourceFile, 'hasValidAst' => true],
            ]);

            $this->assertNull($analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config'));
            $this->assertNotNull($analysisResultCache->loadAnalysisNodes($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesWithFileAnalysisMissesMalformedNodesWhenFactsAreValid(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $this->writeNodePayload($cacheDirectory, $sourceFile, 'config', [
                'metadata'     => $this->nodeMetadata($sourceFile, 'config'),
                'classNodes'   => 'invalid',
                'fileAnalysis' => new FileAnalysis($sourceFile, false, true, null, true, true, false, 1),
            ]);

            $this->assertNull($analysisResultCache->loadAnalysisNodesWithFileAnalysis($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesMissWhenFileMetadataChanges(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', [$this->makeClassNode($sourceFile)]);
            file_put_contents($sourceFile, '<?php class Foo { public function changed(): void {} }');

            $nextRunCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);

            $this->assertNull($nextRunCache->loadAnalysisNodes($sourceFile, 'config'));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClearResetsSharedFileHashes(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceDirectory     = $this->createTempDirectory();
        $sourceFile          = $sourceDirectory . '/Foo.php';
        $fileHashProvider    = new FileHashProvider();
        $analysisResultCache = new AnalysisResultCache(__DIR__, $fileHashProvider, $cacheDirectory);

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $originalHash = $fileHashProvider->hash($sourceFile);
            file_put_contents($sourceFile, '<?php final class Foo {}');

            $this->assertSame($originalHash, $fileHashProvider->hash($sourceFile));

            $analysisResultCache->clear();

            $this->assertSame(hash_file('xxh128', $sourceFile), $fileHashProvider->hash($sourceFile));
            $this->assertNotSame($originalHash, $fileHashProvider->hash($sourceFile));
        } finally {
            unlink($sourceFile);
            $this->removeTempDirectory($sourceDirectory);
            $this->removeTempDirectory($cacheDirectory);
        }
    }

    public function testClassNodesHitWhenOnlyFileMtimeChanges(): void
    {
        $cacheDirectory      = $this->createTempDirectory();
        $sourceFile          = $cacheDirectory . '/Foo.php';
        $analysisResultCache = new AnalysisResultCache(__DIR__, new FileHashProvider(), $cacheDirectory);
        $classNodes          = [$this->makeClassNode($sourceFile)];

        file_put_contents($sourceFile, '<?php class Foo {}');

        try {
            $analysisResultCache->storeAnalysisNodes($sourceFile, 'config', $classNodes);
            touch($sourceFile, 1234567890);

            $this->assertEquals(
                $classNodes,
                $analysisResultCache->loadAnalysisNodes($sourceFile, 'config')['classNodes'] ?? null
            );
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
            $metadata = (new AnalysisCacheMetadataFactory(new FileHashProvider()))->metadata(
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
                (new AnalysisCacheMetadataFactory(new FileHashProvider()))->key($metadata),
                (new AnalysisCacheMetadataFactory(new FileHashProvider()))->key($metadata)
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

    public function testMetadataKeyHandlesInvalidUtf8Text(): void
    {
        $metadata = [
            'version'                      => 1,
            'basePath'                     => "/tmp/project\xB1",
            'configPath'                   => "/tmp/project\xB1/structarmed.php",
            'composerGeneratedVersionHash' => 'same',
            'scanPaths'                    => ["src\xB1"],
        ];

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/',
            (new AnalysisCacheMetadataFactory(new FileHashProvider()))->key($metadata)
        );
    }

    public function testMetadataIncludesComposerGeneratedVersionHash(): void
    {
        $directory = $this->createTempDirectory();
        $config    = $directory . '/structarmed.php';
        $source    = $directory . '/Example.php';

        file_put_contents($config, '<?php return null;');
        file_put_contents($source, '<?php class Example {}');

        try {
            $metadata = (new AnalysisCacheMetadataFactory(new FileHashProvider()))->metadata(
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
                (new AnalysisCacheMetadataFactory(new FileHashProvider()))->composerGeneratedVersionHash()
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
            $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory(new FileHashProvider());
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
            $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory(new FileHashProvider());
            $analysisResultCache          = new AnalysisResultCache($directory, new FileHashProvider(), $cacheDir);
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
            className:     Foo::class,
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

    private function firstNodeFile(string $cacheDirectory): string
    {
        $files = glob($cacheDirectory . '/analysis-nodes-*.bin') ?: [];
        $this->assertNotSame([], $files);

        return $files[0];
    }

    private function removeTempDirectory(string $path): void
    {
        foreach ([...glob($path . '/*.json') ?: [], ...glob($path . '/*.bin') ?: []] as $file) {
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
     * @param array<mixed, mixed> $payload
     */
    private function writeCachePayload(string $cacheDirectory, array $payload, string $filename = 'key.json'): void
    {
        $isAbsolute = str_starts_with($filename, '/')
            || str_starts_with($filename, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $filename) === 1;
        $path       = $isAbsolute ? $filename : $cacheDirectory . '/' . $filename;

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{namespace: string, file: string, hash: string}
     */
    private function nodeMetadata(string $file, string $namespace): array
    {
        return [
            'namespace' => $namespace,
            'file'      => $file,
            'hash'      => hash('xxh128', (string) file_get_contents($file)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeNodePayload(string $cacheDirectory, string $file, string $namespace, array $payload): void
    {
        file_put_contents(
            $cacheDirectory . '/analysis-nodes-' . hash('xxh128', $namespace . "\0" . $file) . '.bin',
            serialize($payload)
        );
    }
}
