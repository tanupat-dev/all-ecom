<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use InvalidArgumentException;

/**
 * Sets the canonical status, enforcing the channel's lifecycle subset
 * (CONTEXT.md: Order Status). No strict transition machine — snapshot
 * imports legitimately skip states (ADR 0004).
 */
class SetOrderStatus
{
    public function handle(Order $order, OrderStatus $status): Order
    {
        if (! in_array($status, OrderStatus::allowedFor($order->platform_type), true)) {
            throw new InvalidArgumentException(
                "Status [{$status->value}] does not exist in the {$order->platform_type->value} lifecycle.",
            );
        }

        $order->update(['status' => $status]);

        return $order;
    }
}
