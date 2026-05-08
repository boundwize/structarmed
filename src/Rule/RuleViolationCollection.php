<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

/**
 * @implements IteratorAggregate<int, RuleViolation>
 */
final class RuleViolationCollection implements Countable, IteratorAggregate
{
    /** @var RuleViolation[] */
    private array $violations = [];

    public function add(RuleViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    public function merge(self $other): void
    {
        foreach ($other as $violation) {
            $this->add($violation);
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
    public function forLayer(string $layer): array
    {
        return array_values(
            array_filter(
                $this->violations,
                static fn(RuleViolation $v) => $v->layer === $layer
            )
        );
    }

    /** @return RuleViolation[] */
    public function forRule(string $ruleKey): array
    {
        return array_values(
            array_filter(
                $this->violations,
                static fn(RuleViolation $v) => $v->ruleKey === $ruleKey
            )
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn(RuleViolation $v) => $v->toArray(),
            $this->violations
        );
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
