<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use PhpParser\Node\Name;

/**
 * Facts collected while traversing a class-like: body-level references
 * plus its members, each recorded as the traverser passes the declaring node.
 *
 * @internal
 */
final class ClassLikeAnalysis
{
    /** @var array<string, true> */
    public array $dependencies = [];

    /**
     * The dependencies used as a class-like name: not only as a function
     * call or constant fetch name, nor only by `use function`/`use const`.
     *
     * @var array<string, true>
     */
    public array $classDependencies = [];

    /**
     * The constants fetched by name, kept apart from the dependencies: a
     * class-like or function of the same name is not a constant fetch.
     *
     * @var array<string, true>
     */
    public array $constantFetches = [];

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
