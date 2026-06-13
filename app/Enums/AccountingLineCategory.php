<?php

namespace App\Enums;

/**
 * The canonical bucket an Accounting Entry line maps onto (CONTEXT.md:
 * Accounting Line Category; ADR 0020) — spanning both the income/contra
 * side (the gross sale, refunds) and the fee/deduction side, because a
 * Platform's settlement breakdown for an Order contains both. The signed
 * lines sum to the net the Platform actually transferred (= Actual Net).
 * Extensible; the Platform's original column name is kept separately in
 * source_field for drilldown.
 */
enum AccountingLineCategory: string
{
    // Income / contra side.
    case SaleIncome = 'sale_income';
    case Refund = 'refund';

    // Fee / deduction side.
    case Commission = 'commission';
    case PaymentFee = 'payment_fee';
    case ShippingSellerPaid = 'shipping_seller_paid';
    case ShippingReturn = 'shipping_return';
    case MarketingFee = 'marketing_fee';
    case AffiliateFee = 'affiliate_fee';
    case TaxWithheld = 'tax_withheld';
    case Other = 'other';
}
