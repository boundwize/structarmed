<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisCacheMetadataFactory;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(AnalysisCacheMetadataFactory::class)]
final class AnalysisCacheMetadataFactoryTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testCombinedHashTracksEvaluatedConfigurationAndRootFileContents(): void
    {
        $config = $this->makeTemporaryFile('structarmed-config-hash');
        file_put_contents($config, '<?php return $architecture;');
        $factory    = new AnalysisCacheMetadataFactory(new FileHashProvider());
        $one        = Architecture::define()->layer('Domain', 'src/Domain/');
        $equivalent = Architecture::define()->layer('Domain', 'src/Domain/');
        $changed    = Architecture::define()->layer('Domain', 'src/Changed/');
        $before     = $factory->configurationHash($one, $config);

        $this->assertSame($before, $factory->configurationHash($equivalent, $config));
        $this->assertNotSame($before, $factory->configurationHash($changed, $config));

        file_put_contents($config, '<?php /* edited root */ return $architecture;');
        $nextRun = new AnalysisCacheMetadataFactory(new FileHashProvider());
        $this->assertNotSame($before, $nextRun->configurationHash($one, $config));
    }
}
