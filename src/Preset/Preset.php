<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset;

use Boundwize\StructArmed\Preset\Presets\DddPreset;
use Boundwize\StructArmed\Preset\Presets\MvcPreset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;

/**
 * Factory for built-in presets.
 *
 * Usage:
 *   ->withPreset(Preset::DDD())
 *   ->withPreset(Preset::DDD(maxComplexity: 3))
 *   ->withPreset(Preset::PSR4())
 *   ->withPresets(Preset::DDD(), Preset::MVC())
 */
final class Preset
{
    /**
     * @param list<string> $sourcePaths
     */
    public static function PSR4(
        ?array $sourcePaths = null,
    ): Psr4Preset {
        return new Psr4Preset(
            sourcePaths: $sourcePaths,
        );
    }

    public static function DDD(
        int $maxComplexity = 5,
        int $maxMethodLength = 20,
        bool $enforceFinalEntities = true,
        bool $enforceFinalValueObjects = true,
        bool $enforceFinalEvents = true,
    ): DddPreset {
        return new DddPreset(
            maxComplexity:           $maxComplexity,
            maxMethodLength:         $maxMethodLength,
            enforceFinalEntities:    $enforceFinalEntities,
            enforceFinalValueObjects: $enforceFinalValueObjects,
            enforceFinalEvents:      $enforceFinalEvents,
        );
    }

    public static function MVC(
        int $controllerMaxComplexity = 5,
        int $controllerMaxMethodLength = 20,
        int $controllerMaxDependencies = 5,
        int $viewMaxComplexity = 3,
    ): MvcPreset {
        return new MvcPreset(
            controllerMaxComplexity:   $controllerMaxComplexity,
            controllerMaxMethodLength: $controllerMaxMethodLength,
            controllerMaxDependencies: $controllerMaxDependencies,
            viewMaxComplexity:         $viewMaxComplexity,
        );
    }
}
