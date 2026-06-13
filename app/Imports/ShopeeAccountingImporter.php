<?php

namespace App\Imports;

use App\Enums\AccountingLineCategory;
use App\Support\Money;
use DateTimeZone;

/**
 * Shopee "Income" accounting export (reference: `ref doc/shopee/Accounting
 * shopee 2.xlsx`, sheet "Income"). One wide row per Order; the data sheet is
 * the second of three and the header sits on the 4th emitted row — Shopee
 * prints a seller/date-range/grand-total preamble above it (the two fully
 * blank preamble rows the file also carries are dropped by the streaming
 * reader, so the header lands at emitted row 4, ADR 0019 anchor-on-real-file).
 *
 * The income leg (สินค้าราคาปกติ, +) and every signed fee/refund/shipping
 * column become Accounting Entry lines that must sum to the file's own
 * จำนวนเงินทั้งหมดที่โอนแล้ว — the base class fail-loud cross-checks that sum
 * (ADR 0020), so a column this table forgot to map is caught, never silently
 * defaulted (ADR 0005).
 */
class ShopeeAccountingImporter extends MarketplaceAccountingImporter
{
    private const ORDER_ID = 'หมายเลขคำสั่งซื้อ';

    private const SETTLEMENT_DATE = 'วันที่โอนชำระเงินสำเร็จ';

    private const TRANSFERRED_TOTAL = 'จำนวนเงินทั้งหมดที่โอนแล้ว (฿)';

    /**
     * The explicit money-column → category table (ADR 0005: an unmapped value
     * is never silently bucketed). Signs are taken from the file as-is — the
     * fee columns are already negative, สินค้าราคาปกติ positive. Columns the
     * file carries but this table omits (e.g. a Shopee-funded discount, which
     * does not reduce the seller's net) are deliberately excluded; if one ever
     * carries a value that affects the transferred total, the reconciliation
     * cross-check fails loud rather than mis-stating Actual Net.
     */
    private const COLUMN_MAP = [
        // Income.
        'สินค้าราคาปกติ' => AccountingLineCategory::SaleIncome,
        // Refund to buyer.
        'จำนวนเงินที่ทำการคืนให้ผู้ซื้อ' => AccountingLineCategory::Refund,
        // Seller-funded discounts / cashback / ad top-up → marketing.
        'ส่วนลดสินค้าจากผู้ขาย' => AccountingLineCategory::MarketingFee,
        'โค้ดส่วนลดที่ออกโดยผู้ขาย' => AccountingLineCategory::MarketingFee,
        'โค้ดส่วนลดร่วมที่ออกโดยผู้ขาย' => AccountingLineCategory::MarketingFee,
        'Coins Cashback ที่สนับสนุนโดยผู้ขาย' => AccountingLineCategory::MarketingFee,
        'Coins Cashback ร่วมที่สนับสนุนโดยผู้ขาย' => AccountingLineCategory::MarketingFee,
        'ค่าธรรมเนียมเติมเงินโฆษณาจากเงิน Escrow' => AccountingLineCategory::MarketingFee,
        // Shipping the seller bears (buyer-paid offsets the Shopee-paid leg).
        'ค่าจัดส่งที่ชำระโดยผู้ซื้อ' => AccountingLineCategory::ShippingSellerPaid,
        'ค่าจัดส่งสินค้าที่ออกโดย Shopee' => AccountingLineCategory::ShippingSellerPaid,
        'ค่าจัดส่งที่ Shopee ชำระโดยชื่อของคุณ' => AccountingLineCategory::ShippingSellerPaid,
        'ค่าธรรมเนียม ของโปรแกรมประหยัดค่าจัดส่ง' => AccountingLineCategory::ShippingSellerPaid,
        // Return shipping.
        'ค่าจัดส่งสินค้าคืน' => AccountingLineCategory::ShippingReturn,
        'ค่าจัดส่งสินค้าคืนผู้ขาย' => AccountingLineCategory::ShippingReturn,
        'โปรแกรมประหยัดค่าจัดส่งคืนสินค้า' => AccountingLineCategory::ShippingReturn,
        // Platform fees.
        'ค่าคอมมิชชั่น AMS' => AccountingLineCategory::Commission,
        'ค่าคอมมิชชั่น' => AccountingLineCategory::Commission,
        'ค่าบริการ' => AccountingLineCategory::Commission,
        'ค่าธรรมเนียมโครงสร้างพื้นฐานแพลตฟอร์ม' => AccountingLineCategory::Commission,
        // Payment transaction fee.
        'ค่าธุรกรรมการชำระเงิน' => AccountingLineCategory::PaymentFee,
        // Withholding tax.
        'ภาษี' => AccountingLineCategory::TaxWithheld,
    ];

    public function sheetName(): ?string
    {
        return 'Income';
    }

    public function headerRowOffset(): int
    {
        return 4;
    }

    /**
     * The settlement column is a Bangkok calendar date (no time) — reset the
     * time fields to midnight ('!') so it never inherits the run's wall clock.
     *
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        return ['!Y-m-d H:i:s', '!Y-m-d'];
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        $orderId = $this->cell($row, self::ORDER_ID);

        $settlementDate = $this->parseBangkokTime($row[self::SETTLEMENT_DATE] ?? null, self::SETTLEMENT_DATE);

        // Shopee prints no cycle id — the per-payout settlement date IS the
        // payout identifier (ADR 0007), so the Bangkok Y-m-d keys the cycle.
        $cycle = $settlementDate !== null
            ? $settlementDate->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('Y-m-d')
            : '';

        $lines = [];

        foreach (self::COLUMN_MAP as $column => $category) {
            $amount = $this->moneyCell($row, $column);

            // Keep the entry clean — a zero column is not a line (it would not
            // change the sum anyway).
            if ($amount->isZero()) {
                continue;
            }

            $lines[] = [
                'source_field' => $column,
                'category' => $category,
                'amount' => $amount,
            ];
        }

        return [
            'order_id' => $orderId,
            'statement_cycle' => $cycle,
            'settlement_date' => $settlementDate,
            'lines' => $lines,
            'transferred_total' => $this->moneyCell($row, self::TRANSFERRED_TOTAL),
        ];
    }
}
