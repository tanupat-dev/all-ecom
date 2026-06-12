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
    public function handle(Shop $shop, NormalizedReturn $normalized): OrderReturn
    {
        if ($normalized->lines === []) {
            throw new InvalidArgumentException('A Return needs at least one Return Line.');
        }

        foreach ($normalized->lines as $line) {
            if ($line['qty'] < 1) {
                throw new InvalidArgumentException('A Return Line qty must be at least 1.');
            }
        }

        return DB::transaction(function () use ($shop, $normalized): OrderReturn {
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
            ];

            if ($return === null) {
                $return = OrderReturn::query()->create($attributes);
            } else {
                $return->update($attributes);
                $return->lines()->delete();
            }

            foreach ($normalized->lines as $line) {
                $return->lines()->create([
                    'ref_order_line_id' => $line['order_line']->id,
                    'qty' => $line['qty'],
                ]);
            }

            return $return->load('lines');
        });
    }
}
