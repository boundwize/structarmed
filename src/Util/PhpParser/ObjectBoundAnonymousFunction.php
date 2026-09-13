<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

use function strcasecmp;

/**
 * Recognises anonymous functions directly bound to an object through the
 * Closure APIs. Static anonymous functions cannot be object-bound.
 */
final class ObjectBoundAnonymousFunction
{
    public static function fromStaticCall(StaticCall $staticCall): Closure|ArrowFunction|null
    {
        if (
            ! $staticCall->class instanceof Name
            || ! self::nameResolvesToClosure($staticCall->class)
            || ! $staticCall->name instanceof Identifier
            || $staticCall->name->toLowerString() !== 'bind'
        ) {
            return null;
        }

        $closureArg = $staticCall->getArg('closure', 0);
        $newThisArg = $staticCall->getArg('newThis', 1);

        if (
            ! $closureArg instanceof Arg
            || (! $closureArg->value instanceof Closure && ! $closureArg->value instanceof ArrowFunction)
            || self::isExplicitNull($newThisArg)
        ) {
            return null;
        }

        return $closureArg->value;
    }

    public static function fromMethodCall(MethodCall $methodCall): Closure|ArrowFunction|null
    {
        if (
            (! $methodCall->var instanceof Closure && ! $methodCall->var instanceof ArrowFunction)
            || ! $methodCall->name instanceof Identifier
        ) {
            return null;
        }

        $methodName = $methodCall->name->toLowerString();

        if ($methodName === 'call') {
            return $methodCall->var;
        }

        if ($methodName !== 'bindto') {
            return null;
        }

        $newThisArg = $methodCall->getArg('newThis', 0);

        return self::isExplicitNull($newThisArg) ? null : $methodCall->var;
    }

    private static function nameResolvesToClosure(Name $name): bool
    {
        $resolvedName = $name->getAttribute('resolvedName');

        if (! $resolvedName instanceof Name) {
            $resolvedName = $name->getAttribute('namespacedName');
        }

        return strcasecmp(
            $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString(),
            'Closure'
        ) === 0;
    }

    private static function isExplicitNull(?Arg $arg): bool
    {
        return $arg instanceof Arg
            && $arg->value instanceof ConstFetch
            && $arg->value->name->toLowerString() === 'null';
    }
}
