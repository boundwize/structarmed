<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

final readonly class MustHaveReturnTypeRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer,
        private ?string $classNamePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if (! $classNode->isInLayer($this->layer)) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return (bool) preg_match($this->classNamePattern, $classNode->className);
        }

        return true;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        return $this->evaluateAll($classNode)[0] ?? null;
    }

    /**
     * @return list<RuleViolation>
     */
    public function evaluateAll(ClassNode $classNode): array
    {
        $violations = [];

        foreach ($classNode->methods as $method) {
            if (! $method->isPublic() || $method->isConstructor()) {
                continue;
            }

            if (! $method->hasReturnType) {
                $violations[] = new RuleViolation(
                    message:   sprintf(
                        'Public method [%s::%s()] must declare a return type',
                        $classNode->className,
                        $method->name
                    ),
                    file:      $classNode->file,
                    line:      $method->line !== 0 ? $method->line : $classNode->line,
                    className: $classNode->className,
                    layer:     $classNode->layer,
                );
            }
        }

        return $violations;
    }
}
