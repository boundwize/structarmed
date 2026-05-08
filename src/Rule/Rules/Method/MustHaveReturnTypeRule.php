<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MustHaveReturnTypeRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
        private readonly ?string $classNamePattern = null,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        if ($node->layer !== $this->layer) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return (bool) preg_match($this->classNamePattern, $node->shortName());
        }

        return true;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        foreach ($node->methods as $method) {
            if (! $method->isPublic() || $method->isConstructor()) {
                continue;
            }

            if (! $method->hasReturnType) {
                return new RuleViolation(
                    ruleKey:   '',
                    message:   sprintf(
                        'Public method [%s::%s()] must declare a return type',
                        $node->className,
                        $method->name
                    ),
                    file:      $node->file,
                    line:      $node->line,
                    className: $node->className,
                    layer:     $node->layer,
                );
            }
        }

        return null;
    }
}
