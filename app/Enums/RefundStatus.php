<?php

namespace App\Enums;

/**
 * The read-only Order-level refund rollup, derived from the Order's
 * Returns (CONTEXT.md: Refund Status; ADR 0006) — never imported directly.
 */
enum RefundStatus: string
{
    case None = 'ไม่มี';
    case Pending = 'รอคืน';
    case Full = 'คืนเต็มจำนวน';
    case Partial = 'คืนบางส่วน';
}
