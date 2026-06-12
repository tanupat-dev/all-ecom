<?php

namespace App\Actions\Pos;

use App\Actions\Orders\CreateOrder;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Variant;
use App\Support\Money;
use LogicException;

/**
 * Parks the cart as an Order held at รอชำระ (CONTEXT.md: Parked Sale) —
 * touches no stock and no money; resume by passing it back into
 * CheckoutPosSale, or void it via VoidParkedSale.
 */
class ParkSale
{
    public function __construct(
        private readonly ResolvePosCart $cart,
        private readonly CreateOrder $createOrder,
    ) {}

    /**
     * @param  list<array{variant: Variant, qty: int, discount_baht?: Money, discount_percent?: float}>  $items
     */
    public function handle(Shift $shift, array $items, ?Money $cartDiscount = null): Order
    {
        if ($shift->status !== ShiftStatus::Open) {
            throw new LogicException('Parking a sale needs an open Shift.');
        }

        [$lines] = $this->cart->handle($items);

        $shop = $shift->register()->firstOrFail()->shop()->firstOrFail();

        return $this->createOrder->handle($shop, $lines, cartDiscount: $cartDiscount);
    }
}
