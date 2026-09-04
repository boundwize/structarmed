<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;

/**
 * An anonymous class declaration (`new class ... {}`). Anonymous classes never
 * become ClassNodes — they cannot be referenced by name — so one is identified
 * by its file and line, plus the named class-like and/or function it is
 * declared in, and rules target it through
 * {@see \Boundwize\StructArmed\Rule\AnonymousClassRuleInterface}.
 *
 * The class it extends, the interfaces it implements, and the traits it uses
 * are still used within the scanned paths, which usage-aware rules must take
 * into account: MustBeFinalRule must skip a class extended by an anonymous class.
 *
 * Its parent chain is resolved by the analyser like a named class's, so
 * {@see extendsClass()} and {@see implementsInterface()} see transitive
 * parents too.
 *
 * Its members and body-level facts are collected like a named class's. The
 * body-level facts of an anonymous class declared inside a class-like or
 * named function are also counted on that enclosing node, exactly as the
 * body of a closure is: a rule that only inspects the enclosing node keeps
 * seeing everything the anonymous class does. Its members belong to the
 * anonymous class alone.
 */
final class AnonymousClassNode
{
    use MemberQueryTrait;
    use NodeQueryTrait;
    use RecursiveParentsTrait;

    /**
     * Scope label reported by {@see enclosingScopeName()} for an anonymous
     * class declared outside any class-like or named function.
     */
    public const FILE_SCOPE = 'file scope';

    /** @var list<string> */
    public readonly array $layers;

    /**
     * @param string[]       $implements            Interface names this anonymous class implements
     * @param string[]       $traits                Trait names this anonymous class uses
     * @param string|null    $enclosingClassName    Innermost named class-like this anonymous class is declared in
     * @param string|null    $enclosingFunctionName Innermost named function this anonymous class is declared in
     * @param bool           $hasEmptyParentheses   Whether `()` follows `class` although no constructor argument
     *                                              is passed: `new class () {}` rather than `new class {}`
     * @param list<string>   $layers                Layer names this anonymous class belongs to; defaults to [$layer]
     * @param list<string>   $parentClasses         Direct and transitive parent class names
     * @param list<string>   $parentInterfaces      Direct and transitive implemented interface names
     * @param list<string>   $dependencies          Fully-qualified class, function, or constant dependencies
     * @param MethodNode[]   $methods               Methods of this anonymous class
     * @param ConstantNode[] $constants             Constants of this anonymous class
     * @param PropertyNode[] $properties            Properties of this anonymous class
     * @param string[]       $functionCalls         Functions called within this anonymous class
     * @param string[]       $superglobals          Superglobals accessed ($_GET, $_POST, etc.)
     * @param string[]       $languageConstructs    Language constructs used (exit, die, etc.)
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $extends,
        public readonly array $implements = [],
        public readonly array $traits = [],
        public readonly ?string $layer = null,
        public readonly ?string $enclosingClassName = null,
        public readonly ?string $enclosingFunctionName = null,
        public readonly bool $hasEmptyParentheses = false,
        array $layers = [],
        public array $parentClasses = [],
        public array $parentInterfaces = [],
        public readonly bool $isReadonly = false,
        public readonly array $dependencies = [],
        public readonly array $methods = [],
        public readonly array $constants = [],
        public readonly array $properties = [],
        public readonly array $functionCalls = [],
        public readonly array $superglobals = [],
        public readonly array $languageConstructs = [],
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    /**
     * Label of the innermost named scope declaring this anonymous class —
     * the enclosing class-like, else the enclosing named function — or
     * {@see self::FILE_SCOPE} for one declared in top-level procedural code.
     */
    public function enclosingScopeName(): string
    {
        return $this->enclosingClassName ?? $this->enclosingFunctionName ?? self::FILE_SCOPE;
    }
}
