<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\CollectorState;

use Boundwize\StructArmed\Analyser;
use PhpParser\Node\Name;

/**
 * Facts collected while traversing a class-like: body-level references
 * plus its members and the scope state needed while it is active.
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

    /** @var list<Analyser\ClassSignature> */
    public array $signatures = [];

    /** @var list<Name> */
    public array $functionCallNames = [];

    /** @var array<string, true> */
    public array $superglobals = [];

    /** @var array<string, true> */
    public array $languageConstructs = [];

    /** @var string[] */
    public array $traits = [];

    /** @var Analyser\ConstantNode[] */
    public array $constants = [];

    /** @var Analyser\PropertyNode[] */
    public array $properties = [];

    /** @var Analyser\MethodNode[] */
    public array $methods = [];

    /** @var Analyser\EnumCaseNode[] */
    public array $enumCases = [];

    /**
     * @param array{self: string|null, static: string|null, parent: string|null} $instantiationScope
     *        Class names or deferred markers for self/static/parent; anonymous
     *        classes only resolve parent.
     * @param array<string, bool> $methodStaticFlags Lowercased method names and their static flags
     * @param int $functionLikeDepthAtEntry Only function-likes entered after this
     *        depth can capture this class-like's `$this`.
     */
    public function __construct(
        public readonly bool $isInterface,
        public readonly ?string $name,
        public readonly array $instantiationScope,
        public readonly array $methodStaticFlags,
        public readonly int $functionLikeDepthAtEntry,
        /** The direct parent class a `parent` signature type resolves to. */
        public readonly ?string $parent = null,
    ) {
    }
}
