<?php

namespace App\Actions\Orders;

use App\Enums\StockAction;
use App\Models\Order;

/**
 * Order cancelled pre-pack: RELEASE what it reserved — a compensating
 * append, never a mutation (ADR 0003).
 */
class ReleaseOrderStock
{
    public function __construct(
        private readonly MoveOrderStock $move,
    ) {}

    public function handle(Order $order): void
    {
        $this->move->handle($order, StockAction::Release);
    }
}
