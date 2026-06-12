<?php

namespace App\Actions\Orders;

use App\Enums\StockAction;
use App\Models\Order;

/**
 * Order entered รอแพ็ค: RESERVE every line at the fulfilment Location
 * (CONTEXT.md: Reserved Stock). Never blocked by Available — oversell is
 * information (negative Available), not a gate.
 */
class ReserveOrderStock
{
    public function __construct(
        private readonly MoveOrderStock $move,
    ) {}

    public function handle(Order $order): void
    {
        $this->move->handle($order, StockAction::Reserve);
    }
}
