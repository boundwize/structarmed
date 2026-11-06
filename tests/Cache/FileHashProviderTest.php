<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function hash;
use function hash_file;
use function stat;

use const PHP_OS_FAMILY;

#[CoversClass(FileHashProvider::class)]
final class FileHashProviderTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testReturnsXxh128ContentHash(): void
    {
        $file = $this->makeTemporaryFile('structarmed-file-hash');
        file_put_contents($file, '<?php echo "original";');

        $this->assertSame(hash_file('xxh128', $file), (new FileHashProvider())->hash($file));
    }

    public function testMemoisesHashUntilCleared(): void
    {
        $file = $this->makeTemporaryFile('structarmed-file-hash');
        file_put_contents($file, '<?php echo "original";');

        $fileHashProvider = new FileHashProvider();
        $originalHash     = $fileHashProvider->hash($file);

        file_put_contents($file, '<?php echo "changed";');

        $this->assertSame($originalHash, $fileHashProvider->hash($file));

        $fileHashProvider->clear();

        $this->assertSame(hash_file('xxh128', $file), $fileHashProvider->hash($file));
        $this->assertNotSame($originalHash, $fileHashProvider->hash($file));
    }

    public function testDoesNotMemoiseFailedHash(): void
    {
        $directory        = $this->makeTemporaryDirectory('structarmed-file-hash');
        $file             = $directory . '/created-later.php';
        $fileHashProvider = new FileHashProvider();

        $this->assertSame('', $fileHashProvider->hash($file));
        // stat() succeeds on a directory, but it cannot be read to be hashed
        $this->assertSame('', $fileHashProvider->hash($directory));

        file_put_contents($file, '<?php echo "created later";');

        $this->assertSame(hash_file('xxh128', $file), $fileHashProvider->hash($file));
    }

    public function testForFilesCopiesOnlyRequestedMemoisedHashes(): void
    {
        $retainedFile  = $this->makeTemporaryFile('structarmed-file-hash');
        $unrelatedFile = $this->makeTemporaryFile('structarmed-file-hash');
        $missingFile   = $this->makeTemporaryFile('structarmed-file-hash');

        file_put_contents($retainedFile, '<?php echo "retained original";');
        file_put_contents($unrelatedFile, '<?php echo "unrelated original";');
        file_put_contents($missingFile, '<?php echo "missing original";');

        $fileHashProvider = new FileHashProvider();
        $retainedHash     = $fileHashProvider->hash($retainedFile);
        $fileHashProvider->hash($unrelatedFile);

        $providerForFiles = $fileHashProvider->forFiles([$retainedFile, $missingFile]);

        file_put_contents($retainedFile, '<?php echo "retained changed";');
        file_put_contents($unrelatedFile, '<?php echo "unrelated changed";');
        file_put_contents($missingFile, '<?php echo "missing changed";');

        $this->assertSame($retainedHash, $providerForFiles->hash($retainedFile));
        $this->assertSame(hash_file('xxh128', $unrelatedFile), $providerForFiles->hash($unrelatedFile));
        $this->assertSame(hash_file('xxh128', $missingFile), $providerForFiles->hash($missingFile));
    }

    public function testUsesFingerprintedHashOnlyWhileStatSignatureIsUnchanged(): void
    {
        $unchangedFile = $this->makeTemporaryFile('structarmed-file-hash');
        $changedFile   = $this->makeTemporaryFile('structarmed-file-hash');
        $malformedFile = $this->makeTemporaryFile('structarmed-file-hash');

        file_put_contents($unchangedFile, '<?php echo "unchanged";');
        file_put_contents($changedFile, '<?php echo "changed";');
        file_put_contents($malformedFile, '<?php echo "malformed";');

        $storedHash       = hash('xxh128', 'stored');
        $fileHashProvider = new FileHashProvider();
        $fileHashProvider->useFingerprints([
            $unchangedFile => $storedHash . ':' . $this->signature($unchangedFile),
            $changedFile   => $storedHash . ':1:1:1:1',
            $malformedFile => [$storedHash, $this->signature($malformedFile)],
        ]);

        $this->assertSame($storedHash, $fileHashProvider->hash($unchangedFile));
        $this->assertSame(hash_file('xxh128', $changedFile), $fileHashProvider->hash($changedFile));
        $this->assertSame(hash_file('xxh128', $malformedFile), $fileHashProvider->hash($malformedFile));
    }

    public function testFingerprintsOnlyFilesChangedBeforeTheRunStarted(): void
    {
        $fileHashProvider = new FileHashProvider();
        $freshFile        = $this->makeTemporaryFile('structarmed-file-hash');

        file_put_contents($freshFile, '<?php echo "fresh";');

        // changed within the run, so it could change again without a new signature
        $fileHashProvider->hash($freshFile);

        $this->assertNull($fileHashProvider->newFingerprints());

        // this test file was last changed before the run started
        $fileHashProvider->hash(__FILE__);

        $fingerprint = hash_file('xxh128', __FILE__) . ':' . $this->signature(__FILE__);

        $this->assertSame(
            PHP_OS_FAMILY === 'Windows' ? null : [__FILE__ => $fingerprint],
            $fileHashProvider->newFingerprints()
        );
        $this->assertNull($fileHashProvider->newFingerprints());

        $fileHashProvider->clear();
        $fileHashProvider->hash(__FILE__);
        $fileHashProvider->clear();

        $this->assertNull($fileHashProvider->newFingerprints());
    }

    private function signature(string $file): string
    {
        $stat = stat($file);
        $this->assertIsArray($stat);

        return $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['size'] . ':' . $stat['ino'];
    }
}
