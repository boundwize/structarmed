<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use PhpParser\Node\Name;

/**
 * Facts collected while traversing a named class-like: body-level references
 * plus its members, each recorded as the traverser passes the declaring node.
 *
 * @internal
 */
final class ClassLikeAnalysis
{
    /** @var array<string, true> */
    public array $dependencies = [];

    /** @var list<Name> */
    public array $functionCallNames = [];

    /** @var array<string, true> */
    public array $superglobals = [];

    /** @var array<string, true> */
    public array $languageConstructs = [];

    /** @var string[] */
    public array $traits = [];

    /** @var ConstantNode[] */
    public array $constants = [];

    /** @var PropertyNode[] */
    public array $properties = [];

    /** @var MethodNode[] */
    public array $methods = [];

    /** @var EnumCaseNode[] */
    public array $enumCases = [];

    public function __construct(
        public readonly bool $isInterface,
    ) {
    }
}
