<?php

namespace App\Enums;

/**
 * The 8 canonical fee buckets an Accounting Entry line maps onto
 * (CONTEXT.md: Fee Category) — extensible, but every entry corresponds to a
 * real platform-fee component. The Platform's original column name is kept
 * separately in source_field for drilldown.
 */
enum FeeCategory: string
{
    case Commission = 'commission';
    case PaymentFee = 'payment_fee';
    case ShippingSellerPaid = 'shipping_seller_paid';
    case ShippingReturn = 'shipping_return';
    case MarketingFee = 'marketing_fee';
    case AffiliateFee = 'affiliate_fee';
    case TaxWithheld = 'tax_withheld';
    case Other = 'other';
}
