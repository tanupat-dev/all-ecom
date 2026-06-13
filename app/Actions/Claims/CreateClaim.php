<?php

namespace App\Actions\Claims;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Models\Claim;
use App\Models\Order;
use App\Models\OrderReturn;
use InvalidArgumentException;

/**
 * Scaffolds a new Claim (CONTEXT.md: Claim; Issue #79), defaulting status to
 * `eligible` (nothing submitted yet). Enforces the type↔ref invariant: a
 * `return_fee` Claim must name the Return that triggered it; a
 * `shipping_overcharge` Claim attaches to the Order alone and carries none.
 */
class CreateClaim
{
    public function handle(Order $order, ClaimType $type, ?OrderReturn $return = null): Claim
    {
        if ($type === ClaimType::ReturnFee && $return === null) {
            throw new InvalidArgumentException('A return_fee Claim requires the Return that triggered it (ref_return_id).');
        }

        if ($type === ClaimType::ShippingOvercharge && $return !== null) {
            throw new InvalidArgumentException('A shipping_overcharge Claim attaches to the Order alone — it carries no ref_return_id.');
        }

        return Claim::query()->create([
            'claim_type' => $type,
            'status' => ClaimStatus::Eligible,
            'ref_order_id' => $order->id,
            'ref_return_id' => $return?->id,
        ]);
    }
}
