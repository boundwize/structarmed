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

    // violation: missing return type
    public function createdAt()
    {
        return $this->createdAt;
    }
}
