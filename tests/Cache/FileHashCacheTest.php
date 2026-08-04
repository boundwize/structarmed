<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\FileHashCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function hash_file;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(FileHashCache::class)]
final class FileHashCacheTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/structarmed-file-hash-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($this->file, '<?php echo "original";');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testHashMatchesHashFile(): void
    {
        $fileHashCache = new FileHashCache();

        $this->assertSame((string) hash_file('xxh128', $this->file), $fileHashCache->hash($this->file));
    }

    public function testHashIsMemoisedUntilCleared(): void
    {
        $fileHashCache = new FileHashCache();
        $originalHash  = $fileHashCache->hash($this->file);

        file_put_contents($this->file, '<?php echo "changed";');

        $this->assertSame($originalHash, $fileHashCache->hash($this->file));

        $fileHashCache->clear();

        $changedHash = $fileHashCache->hash($this->file);
        $this->assertNotSame($originalHash, $changedHash);
        $this->assertSame((string) hash_file('xxh128', $this->file), $changedHash);
    }

    public function testMissingFileHashesToEmptyString(): void
    {
        $fileHashCache = new FileHashCache();

        $this->assertSame('', @$fileHashCache->hash($this->file . '.missing'));
    }
}
