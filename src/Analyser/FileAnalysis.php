<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class FileAnalysis
{
    public function __construct(
        public string $file,
        public bool $hasUtf8Bom,
        public bool $hasValidUtf8,
        public ?int $invalidPhpTagLine,
        public bool $hasValidAst,
        public bool $declaresSymbols,
        public bool $hasSideEffects,
        public int $sideEffectLine,
    ) {
    }
}
