<?php

namespace App\Imports;

use App\Actions\Listings\ResolvePlatformSku;
use App\Actions\Returns\UpsertReturn;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Listings\UnresolvedPlatformSkuException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Money;
use DateTimeInterface;
use LogicException;

/**
 * The shared half of every platform return importer (ROADMAP Phase 5 /
 * ADR 0006): each row resolves fail-loud against the already-imported
 * order mirror — the Return Order references an Order and an Order Line
 * we must already know, or the row is held (ADR 0005) — then groups by
 * Return Order ID and upserts through the core. A concrete importer
 * contributes the platform's column extraction and its versioned
 * status/type tables.
 */
abstract class MarketplaceReturnImporter extends PlatformFileImporter
{
    /** @var array<string, true> */
    private array $upsertedReturns = [];

    public function __construct(
        private readonly ResolvePlatformSku $resolveSku,
        private readonly UpsertReturn $upsertReturn,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array{return_id: string, return_type: ReturnType, sub_status: ReturnSubStatus, platform_order_id: string, platform_sku: string, qty: int, return_reason: ?string, buyer_note: ?string, refund_amount: ?Money, tracking_number: ?string, requested_at: ?DateTimeInterface, refunded_at: ?DateTimeInterface, refunded?: ?bool}
     */
    abstract protected function normalizeReturnRow(array $row, int $rowNumber): array;

    public function mapRow(array $row, int $rowNumber): array
    {
        $normalized = $this->normalizeReturnRow($row, $rowNumber);

        if ($normalized['return_id'] === '') {
            throw new RowImportException('A row with no Return Order ID cannot be imported.');
        }

        if ($normalized['qty'] < 1) {
            throw new RowImportException("Unmapped returned qty for [{$normalized['return_id']}] — expected a positive number.");
        }

        $order = Order::query()
            ->where('shop_id', $this->shop()->id)
            ->where('platform_order_id', $normalized['platform_order_id'])
            ->first()
            ?? throw new RowImportException("Order [{$normalized['platform_order_id']}] is not in the system yet — import the order file first.");

        try {
            $variant = $this->resolveSku->handle($this->shop(), $normalized['platform_sku']);
        } catch (UnresolvedPlatformSkuException $e) {
            throw new RowImportException($e->getMessage());
        }

        $orderLine = $order->lines()->where('variant_id', $variant->id)->first()
            ?? throw new RowImportException("Order [{$normalized['platform_order_id']}] has no line for SKU [{$normalized['platform_sku']}].");

        return [...$normalized, 'order_line' => $orderLine];
    }

    public function upsertChunk(array $chunk): void
    {
        $byReturn = [];

        foreach ($chunk as $row) {
            $returnId = $row['return_id'];

            if (! is_string($returnId)) {
                throw new LogicException('normalizeReturnRow shape drifted.');
            }

            $byReturn[$returnId][] = $row;
        }

        foreach ($byReturn as $returnId => $rows) {
            $first = $rows[0];
            $type = $first['return_type'];
            $subStatus = $first['sub_status'];

            if (! $type instanceof ReturnType || ! $subStatus instanceof ReturnSubStatus) {
                throw new LogicException('normalizeReturnRow shape drifted.');
            }

            $lines = [];

            foreach ($rows as $row) {
                $orderLine = $row['order_line'];
                $qty = $row['qty'];

                if (! $orderLine instanceof OrderLine || ! is_int($qty)) {
                    throw new LogicException('normalizeReturnRow shape drifted.');
                }

                $lines[] = ['order_line' => $orderLine, 'qty' => $qty];
            }

            $reason = $first['return_reason'];
            $note = $first['buyer_note'];
            $refund = $first['refund_amount'];
            $tracking = $first['tracking_number'];
            $requestedAt = $first['requested_at'];
            $refundedAt = $first['refunded_at'];
            $refunded = $first['refunded'] ?? null;

            $this->upsertReturn->handle($this->shop(), new NormalizedReturn(
                platformReturnId: $returnId,
                returnType: $type,
                subStatus: $subStatus,
                lines: $lines,
                returnReason: is_string($reason) && $reason !== '' ? $reason : null,
                buyerNote: is_string($note) && $note !== '' ? $note : null,
                refundAmount: $refund instanceof Money ? $refund : null,
                trackingNumber: is_string($tracking) && $tracking !== '' ? $tracking : null,
                requestedAt: $requestedAt instanceof DateTimeInterface ? $requestedAt : null,
                refundedAt: $refundedAt instanceof DateTimeInterface ? $refundedAt : null,
                refunded: is_bool($refunded) ? $refunded : null,
            ), mergeLines: isset($this->upsertedReturns[$returnId]));

            $this->upsertedReturns[$returnId] = true;
        }
    }
}
