<?php

namespace App\Actions\Orders;

use App\Models\Order;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The defensive milestone merge of ADR 0004: a snapshot fills any
 * newly-populated timestamp but NEVER nulls out one already set — a later
 * export omitting an earlier column must not erase history.
 */
class ApplyOrderMilestones
{
    /**
     * @param  array<string, DateTimeInterface|null>  $milestones
     */
    public function handle(Order $order, array $milestones): Order
    {
        foreach ($milestones as $field => $value) {
            if (! in_array($field, Order::MILESTONES, true)) {
                throw new InvalidArgumentException("[{$field}] is not a milestone field (ADR 0004).");
            }

            if ($value !== null) {
                $order->setAttribute($field, $value);
            }
        }

        $order->save();

        return $order;
    }
}
