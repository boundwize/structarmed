<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\AbstractLayerAwareRule;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\SignatureTypesAwareRuleInterface;

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

            $violations[] = new RuleViolation(
                message:   sprintf(
                    '@api %s [%s] must not expose @internal %s [%s] through %s [%s]',
                    $classNode->getType(),
                    $classNode->className,
                    $typeNode->getType(),
                    $typeNode->className,
                    $signature->isPromoted ? 'promoted ' . $signature->kind : $signature->kind,
                    $signature->name
                ),
                file:      $classNode->file,
                line:      $signature->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }
}
