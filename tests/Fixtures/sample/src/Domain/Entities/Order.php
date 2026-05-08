<?php

declare(strict_types=1);

namespace App\Domain\Entities;

final class Order
{
    private array $items = [];

    public function __construct(
        private readonly string $id,
        private readonly string $customerId,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function addItem(string $sku, int $quantity): void
    {
        $this->items[] = ['sku' => $sku, 'quantity' => $quantity];
    }

    /** @return array<int, array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }
}
