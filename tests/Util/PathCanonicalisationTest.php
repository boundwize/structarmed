<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util;

use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(Path::class)]
final class PathCanonicalisationTest extends TestCase
{
    public function testCanonicalisationMissIsCached(): void
    {
        $directory = sys_get_temp_dir() . '/structarmed-path-' . bin2hex(random_bytes(6));
        $path      = $directory . '/./created.php';

        mkdir($directory);

        try {
            $beforeCreation = Path::normalise($path, canonicalise: true);

            file_put_contents($path, '<?php');

            $afterCreation = Path::normalise($path, canonicalise: true);

            $this->assertSame($path, $beforeCreation);
            $this->assertSame($beforeCreation, $afterCreation);
        } finally {
            if (file_exists($directory . '/created.php')) {
                unlink($directory . '/created.php');
            }

            rmdir($directory);
        }
    }
}
