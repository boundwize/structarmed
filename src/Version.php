<?php

declare(strict_types=1);

namespace Boundwize\StructArmed;

use Composer\InstalledVersions;

final class Version
{
    private const PACKAGE_NAME = 'boundwize/structarmed';

    public static function current(): string
    {
        if (InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'unknown';
        }

        return self::rootPackageVersion();
    }

    private static function rootPackageVersion(): string
    {
        $rootPackage = InstalledVersions::getRootPackage();

        return $rootPackage['pretty_version'];
    }
}
