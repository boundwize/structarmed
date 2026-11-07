<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * Enclosing-scope label shared by {@see AnonymousClassNode} and
 * {@see AnonymousFunctionNode}.
 *
 * @internal
 *
 * @property-read string|null $enclosingClassName    Innermost named class-like the node is declared in
 * @property-read string|null $enclosingFunctionName Innermost named function the node is declared in
 */
trait ScopeTrait
{
    /**
     * Scope label reported by {@see enclosingScopeName()} for an anonymous
     * class/function declared outside any class-like or named function.
     */
    public const FILE_SCOPE = 'file scope';

    /**
     * Label of the innermost named scope declaring this anonymous class/function —
     * the enclosing class-like, else the enclosing named function — or
     * {@see self::FILE_SCOPE} for one declared in top-level procedural code.
     */
    public function enclosingScopeName(): string
    {
        return $this->enclosingClassName ?? $this->enclosingFunctionName ?? self::FILE_SCOPE;
    }
}
