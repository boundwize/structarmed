<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

final readonly class MustHaveReturnTypeRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private ?string $classNamePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if ($classNode->layer !== $this->layer) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return (bool) preg_match($this->classNamePattern, $classNode->shortName());
        }

        return true;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        foreach ($classNode->methods as $method) {
            if (! $method->isPublic() || $method->isConstructor()) {
                continue;
            }

            if (! $method->hasReturnType) {
                return new RuleViolation(
                    ruleKey:   '',
                    message:   sprintf(
                        'Public method [%s::%s()] must declare a return type',
                        $classNode->className,
                        $method->name
                    ),
                    file:      $classNode->file,
                    line:      $classNode->line,
                    className: $classNode->className,
                    layer:     $classNode->layer,
                );
            }
        }

        return null;
    }
}
