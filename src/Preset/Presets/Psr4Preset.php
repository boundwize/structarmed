<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4DirectoryExistsRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4RootPathRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;

final readonly class Psr4Preset implements PresetInterface
{
    use ResolvesSourceLayerNameTrait;

    public const SOURCE_PATHS_MUST_BE_IN_COMPOSER = 'psr4.source_paths.must_be_in_composer';

    public const SOURCE_PATHS_MUST_EXIST_ON_DISK = 'psr4.source_paths.must_exist_on_disk';

    public const CLASSES_MUST_MATCH_COMPOSER = 'psr4.classes.must_match_composer';

    public const SOURCE_PATHS_MUST_NOT_BE_ROOT = 'psr4.source_paths.must_not_be_root';

    /**
     * @param list<string> $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $layerName = $this->resolveLayerName($architecture);
        $architecture->layer($layerName, $this->sourcePaths ?? []);

        $architecture->rule(
            self::CLASSES_MUST_MATCH_COMPOSER,
            new Psr4NamespaceRule($layerName)
        );
        $architecture->rule(
            self::SOURCE_PATHS_MUST_BE_IN_COMPOSER,
            new Psr4SourcePathsRule($this->sourcePaths)
        );
        $architecture->rule(self::SOURCE_PATHS_MUST_EXIST_ON_DISK, new Psr4DirectoryExistsRule());
        $architecture->rule(self::SOURCE_PATHS_MUST_NOT_BE_ROOT, new Psr4RootPathRule());
    }
}
