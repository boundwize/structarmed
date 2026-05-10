<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Preset\Presets;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\PresetInterface;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassConstantNameMustBeUpperCaseRule;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustBeStudlyCapsRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4NamespaceRule;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1PhpTagsRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1SymbolsOrSideEffectsRule;
use Boundwize\StructArmed\Rule\Rules\File\Psr1Utf8WithoutBomRule;
use Boundwize\StructArmed\Rule\Rules\Method\MethodNameMustBeCamelCaseRule;

final readonly class Psr1Preset implements PresetInterface
{
    public const SOURCE_LAYER = 'Source';

    public const FILES_MUST_USE_VALID_TAGS = 'psr1.files.must_use_valid_tags';

    public const FILES_MUST_USE_UTF8_WITHOUT_BOM = 'psr1.files.must_use_utf8_without_bom';

    public const FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS = 'psr1.files.should_declare_symbols_or_side_effects';

    public const CLASSES_MUST_FOLLOW_PSR4 = 'psr1.classes.must_follow_psr4';

    public const SOURCE_PATHS_MUST_BE_IN_COMPOSER = 'psr1.source_paths.must_be_in_composer';

    public const CLASSES_MUST_BE_STUDLY_CAPS = 'psr1.classes.must_be_studly_caps';

    public const CLASS_CONSTANTS_MUST_BE_UPPER_CASE = 'psr1.class_constants.must_be_upper_case';

    public const METHODS_MUST_BE_CAMEL_CASE = 'psr1.methods.must_be_camel_case';

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
    ) {
    }

    public function apply(Architecture $architecture): void
    {
        $architecture->layer(self::SOURCE_LAYER, $this->sourcePaths ?? []);
        $architecture->rule(self::FILES_MUST_USE_VALID_TAGS, new Psr1PhpTagsRule($this->sourcePaths));
        $architecture->rule(self::FILES_MUST_USE_UTF8_WITHOUT_BOM, new Psr1Utf8WithoutBomRule($this->sourcePaths));
        $architecture->rule(
            self::FILES_SHOULD_DECLARE_SYMBOLS_OR_SIDE_EFFECTS,
            new Psr1SymbolsOrSideEffectsRule($this->sourcePaths)
        );
        $architecture->rule(self::CLASSES_MUST_FOLLOW_PSR4, new Psr4NamespaceRule(self::SOURCE_LAYER));
        $architecture->rule(self::SOURCE_PATHS_MUST_BE_IN_COMPOSER, new Psr4SourcePathsRule($this->sourcePaths));
        $architecture->rule(self::CLASSES_MUST_BE_STUDLY_CAPS, new ClassNameMustBeStudlyCapsRule(self::SOURCE_LAYER));
        $architecture->rule(
            self::CLASS_CONSTANTS_MUST_BE_UPPER_CASE,
            new ClassConstantNameMustBeUpperCaseRule(self::SOURCE_LAYER)
        );
        $architecture->rule(self::METHODS_MUST_BE_CAMEL_CASE, new MethodNameMustBeCamelCaseRule(self::SOURCE_LAYER));
    }
}
