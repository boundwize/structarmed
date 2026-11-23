<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function array_intersect;
use function sprintf;
use function strtolower;

final readonly class MayNotUseLanguageConstructRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer,
        private string $construct,
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
     * One violation per spelling the class wrote: `die` is an alias of
     * `exit`, so banning either catches both, and each is named as written.
     *
     * @return list<RuleViolation>
     */
    public function evaluateAll(ClassNode $classNode): array
    {
        $construct  = strtolower($this->construct);
        $constructs = $construct === 'exit' || $construct === 'die' ? ['exit', 'die'] : [$construct];
        $violations = [];

        foreach (array_intersect($constructs, $classNode->languageConstructs) as $usedConstruct) {
            $violations[] = new RuleViolation(
                message:   sprintf(
                    '%s [%s] must not use language construct [%s]',
                    $classNode->getType(),
                    $classNode->className,
                    // the configured construct keeps its configured spelling
                    $usedConstruct === $construct ? $this->construct : $usedConstruct
                ),
                file:      $classNode->file,
                line:      $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }
}
