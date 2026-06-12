<?php

namespace App\Enums;

/**
 * The canonical cancellation buckets (CONTEXT.md: Cancellation Reason) —
 * reason CODES, not free text, so they can be totalled and trended.
 * `other` is reachable only through an explicit mapping entry, never an
 * automatic fallback (ADR 0005).
 */
enum CancelReasonCategory: string
{
    case OutOfStock = 'out_of_stock';
    case PricingError = 'pricing_error';
    case BuyerChangedMind = 'buyer_changed_mind';
    case AddressChange = 'address_change';
    case PaymentIssue = 'payment_issue';
    case FailedDelivery = 'failed_delivery';
    case Other = 'other';
}
