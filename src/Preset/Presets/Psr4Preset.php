<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;

final readonly class Psr4Preset implements PresetInterface
{
    public const SOURCE_LAYER = 'Source';

    public const SOURCE_PATHS_MUST_BE_IN_COMPOSER = 'psr4.source_paths.must_be_in_composer';

    public const CLASSES_MUST_MATCH_COMPOSER = 'psr4.classes.must_match_composer';

    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $architecture->layer(self::SOURCE_LAYER, $this->sourcePaths ?? []);
        $architecture->rule(
            self::CLASSES_MUST_MATCH_COMPOSER,
            new Psr4NamespaceRule(self::SOURCE_LAYER)
        );
        $architecture->rule(
            self::SOURCE_PATHS_MUST_BE_IN_COMPOSER,
            new Psr4SourcePathsRule($this->sourcePaths)
        );
    }
}
