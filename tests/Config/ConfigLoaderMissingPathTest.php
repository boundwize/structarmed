<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Config;

use Boundwize\StructArmed\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderMissingPathTest extends TestCase
{
    private const BASE_PATH = '/structarmed-test-project';

    public function testLoadThrowsWhenConfigIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StructArmed config file not found');

        ConfigLoader::load(self::BASE_PATH . '/structarmed-missing-config.php');
    }

    public function testDiscoverThrowsWhenNoConfigExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not find a structarmed.php config file');

        ConfigLoader::discover(self::BASE_PATH);
    }
}
