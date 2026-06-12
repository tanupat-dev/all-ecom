<?php

namespace App\Imports;

use App\Enums\OrderStatus;
use App\Models\Variant;
use App\Support\Money;
use DateTimeInterface;

/**
 * The channel-agnostic shape one platform order normalizes into — what a
 * per-platform importer hands the import core (ROADMAP Phase 4). Status is
 * already canonical (mapped fail-loud via PlatformStatusMapper) and every
 * line's Variant already resolved ((Shop, Platform SKU) → Variant, fail-loud)
 * — both must hold a row BEFORE it reaches the core (ADR 0005).
 */
final readonly class NormalizedOrder
{
    /**
     * @param  list<array{variant: Variant, qty: int, unit_price: Money}>  $lines
     * @param  array<string, DateTimeInterface|null>  $milestones
     */
    public function __construct(
        public string $platformOrderId,
        public OrderStatus $status,
        public array $lines,
        public array $milestones = [],
        public ?string $trackingNumber = null,
        public ?string $buyerName = null,
        public ?string $buyerPhone = null,
    ) {}
}
