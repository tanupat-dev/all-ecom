<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The manual entry path for social/pos Orders (CONTEXT.md: Order).
 * Marketplace Orders are read-only mirrors — they only ever enter via
 * import (Phase 4), never through here.
 */
class CreateOrder
{
    /**
     * @param  list<array{variant: Variant, qty: int, unit_price: Money}>  $lines
     */
    public function handle(Shop $shop, array $lines, ?string $buyerName = null, ?string $buyerPhone = null): Order
    {
        if ($shop->platform_type === PlatformType::Marketplace) {
            throw new LogicException('Marketplace Orders are read-only mirrors — they enter via import only, never manual creation.');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('An Order needs at least one Order Line.');
        }

        foreach ($lines as $line) {
            if ($line['qty'] < 1) {
                throw new InvalidArgumentException('An Order Line qty must be at least 1.');
            }
        }

        return DB::transaction(function () use ($shop, $lines, $buyerName, $buyerPhone): Order {
            $total = Money::fromSatang(0);

            $order = Order::query()->create([
                'shop_id' => $shop->id,
                'platform_type' => $shop->platform_type,
                'status' => OrderStatus::PendingPayment,
                'total' => $total,
                'buyer_name' => $buyerName,
                'buyer_phone' => $buyerPhone,
                'created_date' => now(),
            ]);

            foreach ($lines as $line) {
                $lineTotal = $line['unit_price']->multiply($line['qty']);
                $total = $total->add($lineTotal);

                $order->lines()->create([
                    'variant_id' => $line['variant']->id,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $order->update(['total' => $total]);

            return $order->load('lines');
        });
    }
}
