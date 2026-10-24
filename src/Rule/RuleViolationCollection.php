<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_multisort;
use function array_values;
use function count;

use const SORT_NUMERIC;
use const SORT_STRING;

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

    /**
     * Orders by file and line, so the result does not depend on the order in which
     * the analysis visited files. The sort is stable: violations on the same line
     * keep their source and rule order.
     */
    public function sort(): void
    {
        $files    = array_column($this->violations, 'file');
        $lines    = array_column($this->violations, 'line');
        $position = array_keys($this->violations);

        // The position column breaks ties, so the violations are never compared.
        array_multisort($files, SORT_STRING, $lines, SORT_NUMERIC, $position, $this->violations);
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
