<?php

namespace App\Imports;

use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Models\OrderLine;
use App\Support\Money;
use DateTimeInterface;

/**
 * The channel-agnostic shape one platform return case normalizes into —
 * what a per-platform return importer hands the core (ROADMAP Phase 5).
 * Type and Sub-Status are already canonical (mapped fail-loud, ADR 0005)
 * and every line's Order Line already resolved.
 */
final readonly class NormalizedReturn
{
    /**
     * @param  list<array{order_line: OrderLine, qty: int}>  $lines
     */
    public function __construct(
        public string $platformReturnId,
        public ReturnType $returnType,
        public ReturnSubStatus $subStatus,
        public array $lines,
        public ?string $returnReason = null,
        public ?string $buyerNote = null,
        public ?Money $refundAmount = null,
        public ?string $trackingNumber = null,
        public ?DateTimeInterface $requestedAt = null,
        public ?DateTimeInterface $refundedAt = null,
        // null = derive from refundedAt; a platform stating the refund
        // only as a status (Lazada `Refunded`) passes true explicitly.
        public ?bool $refunded = null,
    ) {}
}
