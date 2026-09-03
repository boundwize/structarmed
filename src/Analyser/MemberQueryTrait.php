<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

/**
 * Member queries shared by {@see ClassNode} and {@see AnonymousClassNode},
 * the two nodes that declare methods, constants, and properties.
 *
 * @internal
 *
 * @property-read MethodNode[]   $methods    Methods of this class-like
 * @property-read ConstantNode[] $constants  Constants of this class-like
 * @property-read PropertyNode[] $properties Properties of this class-like
 */
trait MemberQueryTrait
{
    public function constructorParamCount(): int
    {
        foreach ($this->methods as $method) {
            if ($method->isConstructor()) {
                return $method->paramCount;
            }
        }

        return 0;
    }
}
