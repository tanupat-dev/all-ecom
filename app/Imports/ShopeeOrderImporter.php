<?php

namespace App\Imports;

use App\Enums\CancelledBy;
use App\Enums\CancelReasonCategory;
use App\Enums\OrderStatus;

/**
 * Shopee "All order" export (reference: `ref doc/shopee/`, 59 Thai-header
 * columns, one row per Order Line). Milestones: created/paid/shipped
 * timestamps plus เวลาที่ทำการสั่งซื้อสำเร็จ → completed_date; Shopee
 * exposes no delivered timestamp column, so delivered_date stays null
 * (CONTEXT.md: delivered_date / completed_date).
 */
class ShopeeOrderImporter extends MarketplaceOrderImporter
{
    /**
     * The versioned native-status table (ADR 0005), exactly the values
     * observed in the reference export — a value Shopee adds later is held,
     * never defaulted.
     */
    private const STATUS_MAP = [
        'ที่ต้องจัดส่ง' => OrderStatus::AwaitingPack,
        'การจัดส่ง' => OrderStatus::InTransit,
        'จัดส่งสำเร็จแล้ว' => OrderStatus::Delivered,
        'สำเร็จแล้ว' => OrderStatus::Completed,
        'ยกเลิกแล้ว' => OrderStatus::Cancelled,
    ];

    /**
     * Statuses Shopee suffixes with variable text (a date), matched on the
     * stable prefix — still an explicit table entry, not a fallback.
     */
    private const STATUS_PREFIX_MAP = [
        // "ผู้ซื้อได้รับสินค้าแล้ว โปรดทราบว่า…ได้จนถึง YYYY-MM-DD"
        'ผู้ซื้อได้รับสินค้าแล้ว' => OrderStatus::Delivered,
    ];

    /**
     * Who the bundled cancel string attributes the cancel to
     * (CONTEXT.md: Cancellation Reason).
     */
    private const CANCELLED_BY_MAP = [
        'ผู้ซื้อ' => CancelledBy::Buyer,
        'ผู้ขาย' => CancelledBy::Seller,
        'อัตโนมัติจากระบบของ Shopee' => CancelledBy::System,
    ];

    /**
     * Raw reason → canonical bucket, exactly the values observed in the
     * reference export; `other` only via these explicit entries (ADR 0005).
     */
    private const CANCEL_REASON_MAP = [
        'ไม่มีการชำระเงิน' => CancelReasonCategory::PaymentIssue,
        'ขั้นตอนการชำระเงินซับซ้อนเกินไป' => CancelReasonCategory::PaymentIssue,
        'การจัดส่งไม่สำเร็จ' => CancelReasonCategory::FailedDelivery,
        'สินค้าหมด' => CancelReasonCategory::OutOfStock,
        'เจอสินค้าเดียวกันที่ถูกกว่า' => CancelReasonCategory::BuyerChangedMind,
        'ไม่ต้องการซื้อสินค้านี้แล้ว' => CancelReasonCategory::BuyerChangedMind,
        'ต้องการเปลี่ยนที่อยู่ในการจัดส่ง' => CancelReasonCategory::AddressChange,
        'จำเป็นต้องเปลี่ยนที่อยู่ในการจัดส่ง' => CancelReasonCategory::AddressChange,
        'ต้องการแก้ไขรายละเอียดคำสั่งซื้อ' => CancelReasonCategory::Other,
        'ผู้ขายไม่ตอบสนองการสอบถามข้อมูล' => CancelReasonCategory::Other,
        'ต้องการเพิ่ม/เปลี่ยนโค้ดส่วนลด' => CancelReasonCategory::Other,
        'อื่นๆ' => CancelReasonCategory::Other,
        'อื่น ๆ' => CancelReasonCategory::Other,
    ];

    public function map(string $nativeStatus): OrderStatus
    {
        if (isset(self::STATUS_MAP[$nativeStatus])) {
            return self::STATUS_MAP[$nativeStatus];
        }

        foreach (self::STATUS_PREFIX_MAP as $prefix => $status) {
            if (str_starts_with($nativeStatus, $prefix)) {
                return $status;
            }
        }

        throw new UnmappedPlatformStatusException("ระบบไม่รองรับ — unmapped Shopee status [{$nativeStatus}].");
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        $qty = $this->cell($row, 'จำนวน');
        [$cancelledBy, $cancelCategory, $cancelSource] = $this->parseCancelString(
            $this->cell($row, 'เหตุผลในการยกเลิกคำสั่งซื้อ'),
        );

        return [
            'order_id' => $this->cell($row, 'หมายเลขคำสั่งซื้อ'),
            'native_status' => $this->cell($row, 'สถานะการสั่งซื้อ'),
            'platform_sku' => $this->cell($row, 'เลขอ้างอิง SKU (SKU Reference No.)'),
            'qty' => is_numeric($qty) ? (int) $qty : 0,
            // ราคาขาย is the Effective Price (CONTEXT.md: Effective Price).
            'unit_price' => $this->cell($row, 'ราคาขาย'),
            'milestones' => [
                'created_date' => $this->parseBangkokTime($row['วันที่ทำการสั่งซื้อ'] ?? null, 'วันที่ทำการสั่งซื้อ'),
                'paid_date' => $this->parseBangkokTime($row['เวลาการชำระสินค้า'] ?? null, 'เวลาการชำระสินค้า'),
                'shipped_date' => $this->parseBangkokTime($row['เวลาส่งสินค้า'] ?? null, 'เวลาส่งสินค้า'),
                'completed_date' => $this->parseBangkokTime($row['เวลาที่ทำการสั่งซื้อสำเร็จ'] ?? null, 'เวลาที่ทำการสั่งซื้อสำเร็จ'),
            ],
            'tracking_number' => $this->cell($row, '*หมายเลขติดตามพัสดุ'),
            // PII minimised: the buyer username only — recipient name,
            // phone, and address never leave the file.
            'buyer_name' => $this->cell($row, 'ชื่อผู้ใช้ (ผู้ซื้อ)'),
            'cancelled_by' => $cancelledBy,
            'cancel_reason_category' => $cancelCategory,
            'cancel_reason_source' => $cancelSource,
        ];
    }

    /**
     * Shopee bundles attribution and reason into one string —
     * "ยกเลิกโดย{ผู้ขาย/ผู้ซื้อ/ระบบ} เหตุผล : …", with a stray <br> in
     * system-cancel text (CONTEXT.md: Cancellation Reason). An unparseable
     * non-empty string or an unmapped actor/reason is fail-loud.
     *
     * @return array{?CancelledBy, ?CancelReasonCategory, ?string}
     */
    private function parseCancelString(string $bundled): array
    {
        if ($bundled === '') {
            return [null, null, null];
        }

        $cleaned = trim((string) preg_replace('/<br\s*\/?>/i', ' ', $bundled));

        if (preg_match('/^ยกเลิกโดย(.+?)\s*เหตุผล\s*:\s*(.+)$/u', $cleaned, $m) !== 1) {
            throw new RowImportException("ระบบไม่รองรับ — unparseable Shopee cancel string [{$bundled}].");
        }

        $actor = trim($m[1]);
        $reason = trim($m[2]);

        $by = self::CANCELLED_BY_MAP[$actor]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped Shopee cancel actor [{$actor}].");
        $category = self::CANCEL_REASON_MAP[$reason]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped Shopee cancel reason [{$reason}].");

        return [$by, $category, $reason];
    }
}
