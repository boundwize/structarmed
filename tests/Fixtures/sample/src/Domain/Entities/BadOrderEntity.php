<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTime;

// Intentionally violating: uses DateTime instead of DateTimeImmutable
// Used in tests to assert violations are detected
class BadOrderEntity
{
    public function __construct(
        private readonly string $id,
        private readonly DateTime $createdAt, // violation: should be DateTimeImmutable
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function createdAt(): DateTime
    {
        return $this->createdAt;
    }
}
