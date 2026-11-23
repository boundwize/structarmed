<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function in_array;
use function sprintf;
use function strtolower;

final readonly class MayNotUseLanguageConstructRule implements RuleInterface
{
    /**
     * @param bool $matchAlias Whether banning `exit` also catches its alias `die`, and the other
     *                         way around. Two rules banning one spelling each turn it off, so a
     *                         usage is reported once, by the rule named after the spelling written.
     */
    public function __construct(
        private string $layer,
        private string $construct,
        private bool $matchAlias = true,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $isUsed = $this->matchAlias
            ? $classNode->usesLanguageConstruct($this->construct)
            : in_array(strtolower($this->construct), $classNode->languageConstructs, true);

        if (! $isUsed) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                '%s [%s] must not use language construct [%s]',
                $classNode->getType(),
                $classNode->className,
                $this->construct
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
