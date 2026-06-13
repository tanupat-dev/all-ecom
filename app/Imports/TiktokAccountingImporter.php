<?php

namespace App\Imports;

use App\Enums\AccountingLineCategory;
use App\Models\ImportJob;
use App\Support\Money;

/**
 * TikTok Shop accounting export (reference: `ref doc/tiktok/Accounting
 * tiktok.xlsx`, sheet "รายละเอียดคำสั่งซื้อ"). Like Shopee it is one wide row
 * per Order; the header sits on row 1 of that sheet.
 *
 * Two row shapes (ADR 0005 — an unrecognised ประเภทธุรกรรม is held, not
 * defaulted):
 *  - คำสั่งซื้อ (order): the income leg (gross goods, +) and every signed
 *    fee/refund/shipping leaf column become Accounting Entry lines that must
 *    sum to the file's own จำนวนเงินที่ชำระทั้งหมด (net settled) — the base
 *    class fail-loud cross-checks that sum (ADR 0020).
 *  - การชดเชยจากแพลตฟอร์ม (platform compensation): a standalone adjustment
 *    (จำนวนการปรับยอด) attached to its related order (หมายเลขคำสั่งซื้อที่
 *    เกี่ยวข้อง) as a single Other line.
 *
 * LEAF vs SUMMARY (verified against the real file — all 1766 order rows
 * reconcile to net): the file carries roll-up columns that would double-count
 * if emitted — รายได้รวม (income total), ยอดรวมค่าสินค้าหลังหักส่วนลดจากผู้ขาย
 * (= gross − discount), ยอดรวมเงินคืนหลังหักส่วนลดจากร้านค้า (= refund legs),
 * ค่าธรรมเนียมทั้งหมด (fee total), ยอดรวมค่าจัดส่งที่ร้านค้าจ่ายจริง (= shipping
 * legs), and ค่าคอมมิชชั่นไม่ใช่แอฟฟิลิเอตก่อนหักภาษีฯ (a before-PIT duplicate
 * of ค่าคอมมิชชั่นแอฟฟิลิเอต). Those are deliberately NOT in the map; only the
 * leaves are. A fee column this map omits that ever carries a value makes the
 * reconciliation cross-check fail loud, never silently mis-states Actual Net
 * (ADR 0005).
 *
 * Cycle + settlement (ADR 0007): the order-details sheet exposes no per-order
 * cycle id and no settlement date. The file's period (ช่วงเวลา) lives in the
 * separate รายงาน sheet, which the single-data-sheet stream never reaches —
 * so the statement cycle MUST be supplied in the ImportJob context
 * (statement_cycle); absent it, every row is held (no cycle is invented).
 * Settlement Date is null for TikTok (not exposed; CONTEXT.md — auto-tune off).
 */
class TiktokAccountingImporter extends MarketplaceAccountingImporter
{
    private const ORDER_ID = 'หมายเลขคำสั่งซื้อ/การปรับ';

    private const TRANSACTION_TYPE = 'ประเภทธุรกรรม';

    private const NET_SETTLED = 'จำนวนเงินที่ชำระทั้งหมด';

    private const ADJUSTMENT_AMOUNT = 'จำนวนการปรับยอด';

    private const RELATED_ORDER_ID = 'หมายเลขคำสั่งซื้อที่เกี่ยวข้อง';

    private const TYPE_ORDER = 'คำสั่งซื้อ';

    private const TYPE_COMPENSATION = 'การชดเชยจากแพลตฟอร์ม';

    /**
     * The explicit leaf money-column → category table (ADR 0005: an unmapped
     * value is never silently bucketed). Signs are taken from the file as-is —
     * fee columns arrive negative, the gross-goods column positive. Summary and
     * before-PIT/duplicate columns are intentionally excluded (see class doc)
     * so they never double-count.
     */
    private const COLUMN_MAP = [
        // Income / contra side (leaves of รายได้รวม).
        'ยอดรวมค่าสินค้าก่อนหักส่วนลด' => AccountingLineCategory::SaleIncome,        // gross goods (+)
        'ส่วนลดจากร้านค้า' => AccountingLineCategory::MarketingFee,                  // seller-funded discount (−)
        'ยอดรวมเงินคืนก่อนหักส่วนลดจากร้านค้า' => AccountingLineCategory::Refund,    // refund (−)
        'เงินคืนจากส่วนลดร้านค้า' => AccountingLineCategory::Refund,                 // refund of the discount (+)

        // Transaction / payment fees.
        'ค่าธรรมเนียมคำสั่งซื้อ' => AccountingLineCategory::PaymentFee,              // transaction fee on customer payment
        'การผ่อนชำระด้วยบัตรเครดิต - มีอัตราดอกเบี้ย' => AccountingLineCategory::PaymentFee, // credit-card installment interest

        // Platform commission / service / infrastructure fees.
        'ค่าคอมมิชชั่น TikTok Shop' => AccountingLineCategory::Commission,
        'ค่าธรรมเนียมการบริการ SFP' => AccountingLineCategory::Commission,
        'ค่าธรรมเนียมบริการคืนเงินโบนัส' => AccountingLineCategory::Commission,
        'ค่าธรรมเนียมสนับสนุนการเติบโตของร้านค้า' => AccountingLineCategory::Commission,
        'ค่าธรรมเนียมโครงสร้างพื้นฐาน' => AccountingLineCategory::Commission,
        'ค่าธรรมเนียมพรีออเดอร์' => AccountingLineCategory::Commission,

        // Shipping the seller bears (platform discount / customer-paid legs offset it).
        'ค่าธรรมเนียมการจัดส่งจริง' => AccountingLineCategory::ShippingSellerPaid,
        'ส่วนลดค่าธรรมเนียมการจัดส่งจากแพลตฟอร์ม' => AccountingLineCategory::ShippingSellerPaid,
        'ค่าธรรมเนียมการจัดส่งของลูกค้า' => AccountingLineCategory::ShippingSellerPaid,
        'เงินสนับสนุนการจัดส่ง' => AccountingLineCategory::ShippingSellerPaid,
        'ค่าจัดส่งสินค้าที่แลกเปลี่ยน (ลูกค้าเป็นผู้จ่าย)' => AccountingLineCategory::ShippingSellerPaid,
        'ค่าจัดส่งสินค้าทดแทน (ลูกค้าเป็นผู้จ่าย)' => AccountingLineCategory::ShippingSellerPaid,
        // Return shipping.
        'ค่าธรรมเนียมการจัดส่งสินค้าคืนตามจริง' => AccountingLineCategory::ShippingReturn,
        'เงินคืนสำหรับค่าจัดส่ง' => AccountingLineCategory::ShippingReturn,

        // Affiliate commissions (net leaves; before-PIT/PIT columns excluded).
        'ค่าคอมมิชชั่นแอฟฟิลิเอต' => AccountingLineCategory::AffiliateFee,
        'ค่าคอมมิชชั่นของพาร์ทเนอร์แอฟฟิลิเอต' => AccountingLineCategory::AffiliateFee,
        'ค่าคอมมิชชั่นโฆษณาร้านค้าแอฟฟิลิเอต' => AccountingLineCategory::AffiliateFee,
        'เงินมัดจำค่าคอมมิชชั่นของแอฟฟิลิเอต' => AccountingLineCategory::AffiliateFee,
        'การคืนเงินค่าคอมมิชชั่นแอฟฟิลิเอต' => AccountingLineCategory::AffiliateFee,
        'ค่าคอมมิชชั่นโฆษณาร้านค้าพาร์ทเนอร์แอฟฟิลิเอต' => AccountingLineCategory::AffiliateFee,

        // Marketing / campaign / coupon programmes.
        'ค่าบริการของคูปองไลฟ์คุ้ม' => AccountingLineCategory::MarketingFee,
        'ค่าบริการคูปอง Xtra' => AccountingLineCategory::MarketingFee,
        'ค่าบริการโปรแกรม EAMS' => AccountingLineCategory::MarketingFee,
        'ค่าบริการแบรนด์ดัง ลดแรง/แฟลชเซล' => AccountingLineCategory::MarketingFee,
        'ค่าธรรมเนียมโปรแกรม TikTok PayLater' => AccountingLineCategory::MarketingFee,
        'ค่าทรัพยากรแคมเปญ' => AccountingLineCategory::MarketingFee,
        'คูปอง GMV Max' => AccountingLineCategory::MarketingFee,
        'ค่าโฆษณา GMV Max' => AccountingLineCategory::MarketingFee,

        // Taxes.
        'ภาษีการขายสำหรับคูปอง GMV Max' => AccountingLineCategory::TaxWithheld,
    ];

    private ?ImportJob $tiktokImportJob = null;

    /**
     * Capture the ImportJob so normalizeRow can read the statement cycle from
     * its context. We keep our own reference rather than touch the shared base
     * (whose $importJob is private and powers shop()).
     */
    public function setImportJob(ImportJob $importJob): void
    {
        parent::setImportJob($importJob);
        $this->tiktokImportJob = $importJob;
    }

    public function sheetName(): ?string
    {
        return 'รายละเอียดคำสั่งซื้อ';
    }

    public function headerRowOffset(): int
    {
        return 1;
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        // Cycle first: a missing context cycle holds every row (ADR 0007 — never
        // invent one), evaluated before any per-row branching.
        $cycle = $this->statementCycle();

        $type = $this->cell($row, self::TRANSACTION_TYPE);

        return match ($type) {
            self::TYPE_ORDER => $this->normalizeOrderRow($row, $cycle),
            self::TYPE_COMPENSATION => $this->normalizeCompensationRow($row, $cycle),
            // Fail-loud on an unrecognised transaction type (ADR 0005).
            default => throw new RowImportException(
                "ระบบไม่รองรับ — unsupported TikTok transaction type [{$type}]."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{order_id: string, statement_cycle: string, settlement_date: null, lines: list<array{source_field: string, category: AccountingLineCategory, amount: Money}>, transferred_total: Money}
     */
    private function normalizeOrderRow(array $row, string $cycle): array
    {
        $lines = [];

        foreach (self::COLUMN_MAP as $column => $category) {
            $amount = $this->moneyCell($row, $column);

            // A zero column is not a line (it would not change the sum anyway).
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
            'order_id' => $this->cell($row, self::ORDER_ID),
            'statement_cycle' => $cycle,
            'settlement_date' => null,
            'lines' => $lines,
            'transferred_total' => $this->moneyCell($row, self::NET_SETTLED),
        ];
    }

    /**
     * A platform-compensation row carries no order/fee legs — only an
     * adjustment (จำนวนการปรับยอด) attached to its related order. The
     * compensation is income the platform pays the seller; it fits no
     * sale/fee bucket, so it is filed under Other by explicit decision
     * (ADR 0005), never an automatic fallback.
     *
     * @param  array<string, mixed>  $row
     * @return array{order_id: string, statement_cycle: string, settlement_date: null, lines: list<array{source_field: string, category: AccountingLineCategory, amount: Money}>, transferred_total: Money}
     */
    private function normalizeCompensationRow(array $row, string $cycle): array
    {
        return [
            'order_id' => $this->cell($row, self::RELATED_ORDER_ID),
            'statement_cycle' => $cycle,
            'settlement_date' => null,
            'lines' => [[
                'source_field' => self::ADJUSTMENT_AMOUNT,
                'category' => AccountingLineCategory::Other,
                'amount' => $this->moneyCell($row, self::ADJUSTMENT_AMOUNT),
            ]],
            'transferred_total' => $this->moneyCell($row, self::NET_SETTLED),
        ];
    }

    /**
     * The statement cycle for this file, from the ImportJob context. TikTok's
     * data sheet has no cycle id and its period lives in an unreachable sheet,
     * so the UI trigger must pass it; absent it the row is held (ADR 0005/0007).
     */
    private function statementCycle(): string
    {
        $cycle = $this->tiktokImportJob?->context['statement_cycle'] ?? null;

        if (! is_string($cycle) || trim($cycle) === '') {
            throw new RowImportException(
                'ระบบไม่รองรับ — TikTok accounting import needs the statement_cycle '
                .'(the รายงาน sheet period, e.g. 2026/04/01-2026/06/05) in its ImportJob context.'
            );
        }

        return trim($cycle);
    }
}
