<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\NodeConcern;

use Boundwize\StructArmed\Analyser;

/**
 * Member queries shared by ClassNode and AnonymousClassNode, the two
 * nodes that declare methods, constants, and properties.
 *
 * @internal
 *
 * @see Analyser\ClassNode
 * @see Analyser\AnonymousClassNode
 *
 * @property-read Analyser\MethodNode[]   $methods    Methods of this class-like
 * @property-read Analyser\ConstantNode[] $constants  Constants of this class-like
 * @property-read Analyser\PropertyNode[] $properties Properties of this class-like
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
