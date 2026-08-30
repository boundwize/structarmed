<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;

/**
 * Body-level facts collected while traversing a named function, closure, or
 * arrow function. Unlike {@see ClassLikeAnalysis}, a function-like has a
 * single body, so its cyclomatic complexity is one counter.
 *
 * @internal
 */
final class FunctionLikeAnalysis
{
    /** @var list<string> */
    public array $dependencies = [];

    /** @var list<Name> */
    public array $functionCallNames = [];

    /** @var string[] */
    public array $superglobals = [];

    /** @var string[] */
    public array $languageConstructs = [];

    public int $cyclomaticComplexity = 1;

    public bool $usesThis = false;

    public function __construct(
        public readonly FunctionLike $functionLike,
        public readonly ?string $enclosingClassName,
        public readonly ?string $enclosingFunctionName,
    ) {
    }
}
