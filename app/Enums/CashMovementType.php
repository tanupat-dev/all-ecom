<?php

namespace App\Enums;

/**
 * Non-sale drawer movements on a Shift (CONTEXT.md: Shift, Paid-in /
 * Paid-out) — recorded BEFORE the cash physically moves so expected_cash
 * stays truthful.
 */
enum CashMovementType: string
{
    case PaidIn = 'paid_in';
    case PaidOut = 'paid_out';
}
