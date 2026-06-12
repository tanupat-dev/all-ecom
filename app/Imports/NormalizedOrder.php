<?php

namespace App\Imports;

use App\Enums\CancelledBy;
use App\Enums\CancelReasonCategory;
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
     * A line may carry an explicit exact `line_total` for exports that
     * only give subtotals which do not divide evenly per unit (then
     * `unit_price` is the floored per-unit figure, ADR 0015); omitted, it
     * is `unit_price × qty`.
     *
     * @param  list<array{variant: Variant, qty: int, unit_price: Money, line_total?: Money}>  $lines
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
        // CONTEXT.md: Cancellation Reason — persisted only on a ยกเลิก
        // order; already mapped fail-loud by the platform importer.
        public ?CancelledBy $cancelledBy = null,
        public ?CancelReasonCategory $cancelReasonCategory = null,
        public ?string $cancelReasonSource = null,
    ) {}
}
