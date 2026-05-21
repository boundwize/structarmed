<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests;

use Boundwize\StructArmed\Version;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @phpstan-type InstalledRootPackage array{
 *     name: string,
 *     pretty_version: string,
 *     version: string,
 *     reference: string|null,
 *     type: string,
 *     install_path: string,
 *     aliases: array<string>,
 *     dev: bool
 * }
 * @phpstan-type InstalledPackageVersion array{
 *     pretty_version?: string,
 *     version?: string,
 *     reference?: string|null,
 *     type?: string,
 *     install_path?: string,
 *     aliases?: array<string>,
 *     dev_requirement: bool,
 *     replaced?: array<string>,
 *     provided?: array<string>
 * }
 * @phpstan-type InstalledVersionsData array{
 *     root: InstalledRootPackage,
 *     versions: array<string, InstalledPackageVersion>
 * }
 */
#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    public function testCurrentUsesInstalledPackagePrettyVersion(): void
    {
        $this->withInstalledVersions([
            'root'     => $this->rootPackage('some/project', 'dev-root'),
            'versions' => [
                'boundwize/structarmed' => [
                    'pretty_version'  => '1.2.3',
                    'version'         => '1.2.3.0',
                    'reference'       => 'abc123',
                    'type'            => 'library',
                    'install_path'    => __DIR__ . '/..',
                    'aliases'         => [],
                    'dev_requirement' => false,
                ],
            ],
        ], static function (): void {
            self::assertSame('1.2.3', Version::current());
        });
    }

    public function testCurrentUsesUnknownWhenInstalledPackageHasNoPrettyVersion(): void
    {
        $this->withInstalledVersions([
            'root'     => $this->rootPackage('some/project', 'dev-root'),
            'versions' => [
                'boundwize/structarmed' => [
                    'version'         => '1.2.3.0',
                    'reference'       => 'abc123',
                    'type'            => 'library',
                    'install_path'    => __DIR__ . '/..',
                    'aliases'         => [],
                    'dev_requirement' => false,
                ],
            ],
        ], static function (): void {
            self::assertSame('unknown', Version::current());
        });
    }

    public function testCurrentUsesRootPackageVersionWhenStructArmedIsNotInstalled(): void
    {
        $this->withInstalledVersions([
            'root'     => $this->rootPackage('some/project', 'dev-root'),
            'versions' => [],
        ], static function (): void {
            self::assertSame('dev-root', Version::current());
        });
    }

    /**
     * @param array<string, mixed> $installedVersions
     * @phpstan-param InstalledVersionsData $installedVersions
     * @param callable(): void $callback
     */
    private function withInstalledVersions(array $installedVersions, callable $callback): void
    {
        $canGetVendors     = new ReflectionProperty(InstalledVersions::class, 'canGetVendors');
        $installed         = new ReflectionProperty(InstalledVersions::class, 'installed');
        $installedByVendor = new ReflectionProperty(InstalledVersions::class, 'installedByVendor');
        $isLocalDir        = new ReflectionProperty(InstalledVersions::class, 'installedIsLocalDir');

        $origCanGetVendors     = $canGetVendors->getValue();
        $origInstalled         = $installed->getValue();
        $origInstalledByVendor = $installedByVendor->getValue();
        $origIsLocalDir        = $isLocalDir->getValue();

        try {
            $canGetVendors->setValue(null, false);
            InstalledVersions::reload($installedVersions);

            $callback();
        } finally {
            $installed->setValue(null, $origInstalled);
            $installedByVendor->setValue(null, $origInstalledByVendor);
            $isLocalDir->setValue(null, $origIsLocalDir);
            $canGetVendors->setValue(null, $origCanGetVendors);
        }
    }

    /**
     * @phpstan-return InstalledRootPackage
     * @return array<string, string|mixed[]|null|bool>
     */
    private function rootPackage(string $name, string $version): array
    {
        return [
            'name'           => $name,
            'pretty_version' => $version,
            'version'        => $version,
            'reference'      => null,
            'type'           => 'project',
            'install_path'   => __DIR__,
            'aliases'        => [],
            'dev'            => true,
        ];
    }
}
