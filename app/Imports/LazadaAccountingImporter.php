<?php

namespace App\Imports;

use App\Enums\AccountingLineCategory;
use App\Support\Money;

/**
 * Lazada "Income Overview" accounting export (reference: `ref doc/lazada/
 * Accounting lazada.xlsx`, sheet "Income Overview", header on row 1). Unlike
 * Shopee's wide one-row-per-Order Income report, this file is a transaction
 * JOURNAL: one fee/income leg PER ROW, many rows under one `รหัสรอบบิล`
 * (statement cycle) for one Order. So each normalizeRow emits exactly ONE
 * line (plus an optional separate withholding-tax line) and the base class
 * accumulates the many rows of one Order/cycle into one Accounting Entry
 * (cross-chunk accumulation handles a journal that splits across chunks).
 *
 * Lazada prints NO transferred-total column, so the base's ADR 0020
 * reconciliation cross-check is skipped (transferred_total => null). That
 * removes the safety net Shopee enjoys — so the only guard against a missed
 * money column here is the EXHAUSTIVE transaction-name → category map: any
 * `ชื่อรายการธุรกรรม` not in the map is held fail-loud (ADR 0005), never
 * silently bucketed as `other`, because a mis-categorised or dropped leg
 * would silently corrupt Actual Net with nothing to catch it.
 */
class LazadaAccountingImporter extends MarketplaceAccountingImporter
{
    private const ORDER_ID = 'หมายเลขคำสั่งซื้อ';

    private const STATEMENT_CYCLE = 'รหัสรอบบิล';

    private const TRANSACTION_NAME = 'ชื่อรายการธุรกรรม';

    private const AMOUNT = 'จำนวนเงิน(รวมภาษี)';

    private const SETTLEMENT_DATE = 'วันที่ปรับปรุงเข้ายอดของฉัน';

    private const WHT_AMOUNT = 'WHT Amount';

    private const WHT_INCLUDED = 'WHT รวมอยู่ในจำนวนเงินแล้ว';

    /**
     * The exhaustive `ชื่อรายการธุรกรรม` → category table (ADR 0005). These are
     * the 9 transaction names the real file carries; a name not listed here is
     * held fail-loud in normalizeRow, never defaulted to `other`. Signs are
     * taken from the file as-is — `จำนวนเงิน(รวมภาษี)` is already signed (fees
     * negative, the gross sale positive), so the categorised lines sum to the
     * net Lazada settled to the Order = Actual Net (ADR 0020).
     */
    private const TRANSACTION_MAP = [
        // Income / contra side.
        'ยอดรวมค่าสินค้า' => AccountingLineCategory::SaleIncome,            // gross sale (+)
        'หักเงินค่าสินค้า (คืนสินค้า)' => AccountingLineCategory::Refund,    // product money deducted for a return (−)
        // Platform commission (sales fee) + its reversal.
        'หักค่าธรรมเนียมการขายสินค้า' => AccountingLineCategory::Commission,        // sales/commission fee (−)
        'คืนส่วนลดค่าธรรมเนียมการขายสินค้า' => AccountingLineCategory::Commission,  // a commission-side reversal (+)
        // Payment processing fee + its refund.
        'ค่าธรรมเนียมการชำระเงิน' => AccountingLineCategory::PaymentFee,    // payment fee (−)
        'คืนค่าธรรมเนียมการชำระเงิน' => AccountingLineCategory::PaymentFee, // payment-fee refund (+)
        // Shipping the seller bears (Lazada free-shipping program).
        'ค่าโปรแกรมส่งฟรีพิเศษกับลาซาด้า' => AccountingLineCategory::ShippingSellerPaid,
        // Seller-funded LazCoins discount + its program fee.
        'ส่วนลด LazCoins' => AccountingLineCategory::MarketingFee,
        'ค่าธรรมเนียมโปรแกรมส่วนลด LazCoins' => AccountingLineCategory::MarketingFee,
    ];

    public function sheetName(): ?string
    {
        return 'Income Overview';
    }

    public function headerRowOffset(): int
    {
        return 1;
    }

    /**
     * Lazada dates are a Bangkok calendar date with no time, "31 May 2026" —
     * reset the time fields to midnight ('!') so the row never inherits the
     * run's wall clock.
     *
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        return ['!j M Y'];
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        $orderId = $this->cell($row, self::ORDER_ID);
        $cycle = $this->cell($row, self::STATEMENT_CYCLE);
        $settlementDate = $this->parseBangkokTime($row[self::SETTLEMENT_DATE] ?? null, self::SETTLEMENT_DATE);

        $transactionName = $this->cell($row, self::TRANSACTION_NAME);

        // Fail-loud (ADR 0005): with no transferred-total to reconcile against,
        // an unknown transaction name is the ONLY thing standing between a
        // silently-dropped fee column and a corrupted Actual Net.
        if (! array_key_exists($transactionName, self::TRANSACTION_MAP)) {
            throw new RowImportException(sprintf(
                'ระบบไม่รองรับ — order [%s]: unknown Lazada transaction name [%s] — add it to the category map (never default to other).',
                $orderId !== '' ? $orderId : '?',
                $transactionName !== '' ? $transactionName : '(blank)',
            ));
        }

        $lines = [[
            'source_field' => $transactionName,
            'category' => self::TRANSACTION_MAP[$transactionName],
            'amount' => $this->moneyCell($row, self::AMOUNT),
        ]];

        // Withholding tax (ADR 0020). The file carries a `WHT Amount` magnitude
        // and a `WHT รวมอยู่ในจำนวนเงินแล้ว` flag stating whether it is already
        // inside `จำนวนเงิน(รวมภาษี)`. When it is NOT included and non-zero, it
        // is a separate deduction — emit an extra signed TaxWithheld line so it
        // reduces Actual Net. When YES, it is already in the amount → no line.
        $wht = $this->moneyCell($row, self::WHT_AMOUNT);

        if (! $wht->isZero()) {
            $included = strtoupper($this->cell($row, self::WHT_INCLUDED));

            if ($included === 'NO') {
                // Sign-robust: always a deduction regardless of the file's sign.
                $lines[] = [
                    'source_field' => self::WHT_AMOUNT,
                    'category' => AccountingLineCategory::TaxWithheld,
                    'amount' => Money::fromSatang(-abs($wht->satang)),
                ];
            } elseif ($included !== 'YES') {
                // Non-zero WHT with an unrecognised inclusion flag is ambiguous
                // and there is no reconciliation to catch a wrong guess — hold
                // it fail-loud rather than over- or under-stating net (ADR 0005).
                throw new RowImportException(sprintf(
                    'ระบบไม่รองรับ — order [%s]: WHT Amount [%s] with unrecognised inclusion flag [%s] (expected YES/NO).',
                    $orderId !== '' ? $orderId : '?',
                    $wht->toBaht(),
                    $included !== '' ? $included : '(blank)',
                ));
            }
        }

        return [
            'order_id' => $orderId,
            'statement_cycle' => $cycle,
            'settlement_date' => $settlementDate,
            'lines' => $lines,
            // Lazada prints no transferred-total → base skips reconciliation.
            'transferred_total' => null,
        ];
    }
}
