<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;
use function str_starts_with;

final readonly class MethodNameMustBeCamelCaseRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
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
            if (str_starts_with($method->name, '__')) {
                continue;
            }

            if ((bool) preg_match('/^[a-z][A-Za-z0-9]*$/', $method->name)) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Method [%s::%s()] must be declared in camelCase',
                    $classNode->className,
                    $method->name
                ),
                file:      $classNode->file,
                line:      $method->line !== 0 ? $method->line : $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }
}
