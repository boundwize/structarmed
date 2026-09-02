<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class FileAnalysis
{
    /**
     * @param list<array{int, string}> $nonCanonicalKeywordConstants `true`, `false`, and `null`
     *                                                               fetches not spelled in lowercase,
     *                                                               as [line, spelling as written]; a
     *                                                               leading `\` marks a fully
     *                                                               qualified form such as `\TRUE`.
     * @param list<array{int, string, int|float}> $numericLiterals Numeric literals as
     *                                                               [line, spelling as written,
     *                                                               evaluated value].
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
    ) {
    }
}
