<?php

declare(strict_types=1);

namespace App\Domain\Entities;

// Intentionally violating: uses DateTime instead of DateTimeImmutable
// Used in tests to assert violations are detected
class BadOrderEntity
{
    public function __construct(
        private string $id,
        private \DateTime $createdAt,  // violation: should be DateTimeImmutable
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function createdAt(): \DateTime
    {
        return $this->createdAt;
    }
}
