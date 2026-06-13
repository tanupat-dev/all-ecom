<?php

namespace App\Enums;

/**
 * Per-Order reconciliation flag comparing Expected Net to Actual Net
 * (CONTEXT.md: Reconciliation Status) — marketplace Orders only. A POS Order
 * has no Reconciliation Status (money in hand at the sale, nothing to settle).
 */
enum ReconciliationStatus: string
{
    case NotYetPaid = 'not_yet_paid';
    case PaidOk = 'paid_ok';
    case PaidMismatch = 'paid_mismatch';
}
