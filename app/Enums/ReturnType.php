<?php

namespace App\Enums;

/**
 * The two return shapes (CONTEXT.md: Return; ADR 0006). `refund_only`
 * never touches stock — no goods come back.
 */
enum ReturnType: string
{
    case ReturnAndRefund = 'return_and_refund';
    case RefundOnly = 'refund_only';
}
