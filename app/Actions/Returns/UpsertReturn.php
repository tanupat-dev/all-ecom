<?php

namespace App\Actions\Returns;

use App\Imports\NormalizedReturn;
use App\Models\OrderReturn;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The channel-agnostic Return upsert (ROADMAP Phase 5 / ADR 0006): deduped
 * on (tenant, shop, platform_return_id) so a re-imported snapshot updates
 * in place. A terminal Return (รับของกลับแล้ว / ยกเลิกการคืน) never
 * reverts — Received already credited stock; Closed is the Platform's
 * final word. Never moves stock — that is Inbound Scan's job alone.
 */
class UpsertReturn
{
    /**
     * $mergeLines: a return importer whose case's rows were split across
     * two pipeline chunks passes true for the later part — the lines
     * accumulate instead of replacing. A fresh occurrence always replaces.
     */
    public function handle(Shop $shop, NormalizedReturn $normalized, bool $mergeLines = false): OrderReturn
    {
        if ($normalized->lines === []) {
            throw new InvalidArgumentException('A Return needs at least one Return Line.');
        }

        foreach ($normalized->lines as $line) {
            if ($line['qty'] < 1) {
                throw new InvalidArgumentException('A Return Line qty must be at least 1.');
            }
        }

        return DB::transaction(function () use ($shop, $normalized, $mergeLines): OrderReturn {
            $return = OrderReturn::query()
                ->where('shop_id', $shop->id)
                ->where('platform_return_id', $normalized->platformReturnId)
                ->lockForUpdate()
                ->first();

            if ($return !== null && $return->sub_status->isTerminal()) {
                return $return->load('lines');
            }

            $orderId = $normalized->lines[0]['order_line']->order_id;

            $attributes = [
                'shop_id' => $shop->id,
                'platform_return_id' => $normalized->platformReturnId,
                'ref_order_id' => $orderId,
                'return_type' => $normalized->returnType,
                'sub_status' => $normalized->subStatus,
                'return_reason' => $normalized->returnReason,
                'buyer_note' => $normalized->buyerNote,
                'refund_amount' => $normalized->refundAmount,
                'tracking_number' => $normalized->trackingNumber,
                'requested_at' => $normalized->requestedAt,
                'refunded_at' => $normalized->refundedAt,
                'refunded' => $normalized->refunded ?? ($normalized->refundedAt !== null),
            ];

            if ($return === null) {
                $return = OrderReturn::query()->create($attributes);
            } else {
                $return->update($attributes);

                if (! $mergeLines) {
                    $return->lines()->delete();
                }
            }

            // Per-unit exports (Lazada) repeat an Order Line across rows —
            // quantities aggregate per line (ADR 0006).
            $qtyByOrderLine = [];

            foreach ($normalized->lines as $line) {
                $qtyByOrderLine[$line['order_line']->id] = ($qtyByOrderLine[$line['order_line']->id] ?? 0) + $line['qty'];
            }

            foreach ($qtyByOrderLine as $orderLineId => $qty) {
                $existing = $return->lines()->where('ref_order_line_id', $orderLineId)->first();

                if ($existing !== null) {
                    $existing->update(['qty' => $existing->qty + $qty]);
                } else {
                    $return->lines()->create(['ref_order_line_id' => $orderLineId, 'qty' => $qty]);
                }
            }

            return $return->load('lines');
        });
    }
}
