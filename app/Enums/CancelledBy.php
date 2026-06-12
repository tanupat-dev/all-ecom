<?php

namespace App\Enums;

/**
 * Who cancelled an Order (CONTEXT.md: Cancellation Reason) — the
 * attribution that matters: Seller cancellations count against the
 * Platform's Cancellation Rate.
 */
enum CancelledBy: string
{
    case Seller = 'seller';
    case Buyer = 'buyer';
    case System = 'system';
}
