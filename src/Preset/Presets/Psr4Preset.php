<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;

final class Psr4Preset implements PresetInterface
{
    private const SOURCE_LAYER = 'Source';

    public const SOURCE_PATHS_MUST_BE_IN_COMPOSER = 'psr4.source_paths.must_be_in_composer';

    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private readonly array $sourcePaths = ['src/'],
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $architecture->layer(self::SOURCE_LAYER, $this->sourcePaths);
        $architecture->projectRule(
            self::SOURCE_PATHS_MUST_BE_IN_COMPOSER,
            new Psr4SourcePathsRule($this->sourcePaths)
        );
    }
}
