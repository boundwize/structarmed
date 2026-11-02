<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ClassSignature;
use Boundwize\StructArmed\Rule\AbstractLayerAwareRule;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\SignatureTypesAwareRuleInterface;

use function array_values;
use function sprintf;

final class ApiMayNotExposeInternalRule extends AbstractLayerAwareRule implements
    MultipleRuleViolationInterface,
    SignatureTypesAwareRuleInterface
{
    public function __construct(
        private readonly string $layer,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isApi && $classNode->isInLayer($this->layer);
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

        foreach ($classNode->signatures as $signature) {
            if (! $signature->isExposed()) {
                continue;
            }

            // Only a scanned type has a known docblock
            $typeNode = $this->getDependencyNode($signature->type);

            if ($typeNode?->isInternal !== true) {
                continue;
            }

            // A promoted parameter is also a property of the same name: one violation
            // is enough. A return type stays apart from a parameter named like its method
            $member = $signature->kind === ClassSignature::METHOD ? $signature->name . '()' : $signature->name;

            $violations[$signature->line . ' ' . $typeNode->className . ' ' . $member] ??= new RuleViolation(
                message:   sprintf(
                    '@api %s [%s] must not expose @internal %s [%s] through %s [%s]',
                    $classNode->getType(),
                    $classNode->className,
                    $typeNode->getType(),
                    $typeNode->className,
                    $signature->kind,
                    $signature->name
                ),
                file:      $classNode->file,
                line:      $signature->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return array_values($violations);
    }
}
