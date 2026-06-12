<?php

namespace App\Actions\Orders;

use App\Enums\StockAction;
use App\Models\Order;

/**
 * Order ships: SHIP every line, order-aware (CONTEXT.md: Stock Movement) —
 * On-Hand always falls; Reserved falls only by what this Order actually
 * reserved (marketplace/social = line qty, POS = 0).
 */
class ShipOrderStock
{
    public function __construct(
        private readonly MoveOrderStock $move,
    ) {}

    public function handle(Order $order): void
    {
        $this->move->handle(
            $order,
            StockAction::Ship,
            reservedReleasedPerUnit: MoveOrderStock::reservedPerUnit($order),
        );
    }
}
