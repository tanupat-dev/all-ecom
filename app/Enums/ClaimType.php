<?php

namespace App\Enums;

/**
 * The two Claim shapes (CONTEXT.md: Claim). `return_fee` attaches to a
 * specific Return (seller-fault bucket); `shipping_overcharge` attaches to
 * the Order alone (courier charged above the expected weight-based rate).
 * The type↔ref invariant is enforced in CreateClaim, not here.
 */
enum ClaimType: string
{
    case ReturnFee = 'return_fee';
    case ShippingOvercharge = 'shipping_overcharge';
}
