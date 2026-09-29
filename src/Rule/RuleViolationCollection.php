<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_filter;
use function array_map;
use function array_values;
use function count;

/**
 * @implements IteratorAggregate<int, RuleViolation>
 */
final class RuleViolationCollection implements Countable, IteratorAggregate
{
    /** @var RuleViolation[] */
    private array $violations = [];

    public function add(RuleViolation $ruleViolation): void
    {
        $this->violations[] = $ruleViolation;
    }

    public function merge(self $other): void
    {
        foreach ($other as $violation) {
            $this->violations[] = $violation;
        }
    }

    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    public function hasViolations(): bool
    {
        return ! $this->isEmpty();
    }

    public function count(): int
    {
        return count($this->violations);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->violations);
    }

    /** @return RuleViolation[] */
    public function forRule(string $ruleKey): array
    {
        return array_values(
            array_filter(
                $this->violations,
                static fn(RuleViolation $ruleViolation): bool => $ruleViolation->ruleKey === $ruleKey
            )
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_values(array_map(
            static fn(RuleViolation $ruleViolation): array => $ruleViolation->toArray(),
            $this->violations
        ));
    }
}
