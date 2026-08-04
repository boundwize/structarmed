<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(AnalysisCacheMetadataFactory::class)]
final class AnalysisCacheMetadataFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }

        $this->temporaryFiles = [];
    }

    public function testConfigHashOfSingleFileMatchesFileHash(): void
    {
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configFile                   = $this->createTemporaryFile('<?php return 1;');

        $this->assertSame(
            $analysisCacheMetadataFactory->fileHash($configFile),
            $analysisCacheMetadataFactory->configHash([$configFile])
        );
    }

    public function testConfigHashChangesWhenImportedFileChanges(): void
    {
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configFile                   = $this->createTemporaryFile('<?php return 1;');
        $importedFile                 = $this->createTemporaryFile('<?php return 2;');

        $hashBefore = $analysisCacheMetadataFactory->configHash([$configFile, $importedFile]);

        file_put_contents($importedFile, '<?php return 3;');

        $this->assertNotSame(
            $hashBefore,
            $analysisCacheMetadataFactory->configHash([$configFile, $importedFile])
        );
    }

    public function testConfigHashIsStableForUnchangedFiles(): void
    {
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configFile                   = $this->createTemporaryFile('<?php return 1;');
        $importedFile                 = $this->createTemporaryFile('<?php return 2;');

        $this->assertSame(
            $analysisCacheMetadataFactory->configHash([$configFile, $importedFile]),
            $analysisCacheMetadataFactory->configHash([$configFile, $importedFile])
        );
    }

    public function testMetadataConfigHashIncludesImportedConfigFiles(): void
    {
        $analysisCacheMetadataFactory = new AnalysisCacheMetadataFactory();
        $configFile                   = $this->createTemporaryFile('<?php return 1;');
        $importedFile                 = $this->createTemporaryFile('<?php return 2;');

        $metadataWithoutImports = $analysisCacheMetadataFactory->metadata('/base', $configFile, [], []);
        $metadataWithImports    = $analysisCacheMetadataFactory->metadata(
            '/base',
            $configFile,
            [],
            [],
            [$importedFile]
        );

        $this->assertNotSame(
            $metadataWithoutImports['configHash'],
            $metadataWithImports['configHash']
        );
    }

    private function createTemporaryFile(string $content): string
    {
        $file = sys_get_temp_dir() . '/structarmed-test-' . uniqid('', true) . '.php';
        file_put_contents($file, $content);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
