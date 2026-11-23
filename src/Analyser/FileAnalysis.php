<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Analyser\NodeConcern\PropertyUnserializeTrait;

final readonly class FileAnalysis
{
    use PropertyUnserializeTrait;

    /**
     * @param list<array{int, string}> $nonCanonicalKeywordConstants `true`, `false`, and `null`
     *                                                               fetches not spelled in lowercase,
     *                                                               as [line, spelling as written]; a
     *                                                               leading `\` marks a fully
     *                                                               qualified form such as `\TRUE`.
     * @param list<array{int, string, int|float}> $numericLiterals Numeric literals as
     *                                                               [line, spelling as written,
     *                                                               evaluated value].
     * @param list<array{int, string}> $attributesWithEmptyParentheses Attributes spelling `()` although
     *                                                               they pass no argument, as
     *                                                               [line, resolved attribute name].
     */
    public function __construct(
        public string $file,
        public bool $hasUtf8Bom,
        public bool $hasValidUtf8,
        public ?int $invalidPhpTagLine,
        public bool $hasValidAst,
        public bool $declaresSymbols,
        public bool $hasSideEffects,
        public int $sideEffectLine,
        public array $nonCanonicalKeywordConstants = [],
        public array $numericLiterals = [],
        public array $attributesWithEmptyParentheses = [],
    ) {
    }
}
