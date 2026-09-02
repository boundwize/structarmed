<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function hash_file;

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

        $this->assertSame('', @$fileHashProvider->hash($file));

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
}
