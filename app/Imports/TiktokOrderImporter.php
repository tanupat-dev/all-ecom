<?php

namespace App\Imports;

use App\Enums\CancelledBy;
use App\Enums\CancelReasonCategory;
use App\Enums\OrderStatus;
use App\Support\Money;

/**
 * TikTok order export (reference: `ref doc/tiktok/All order tiktok.csv`,
 * 63 English headers, CSV, one row per SKU line with a Quantity column;
 * timestamp cells carry a trailing tab). The **Order Substatus** column is
 * the native status — it distinguishes in-transit from delivered, which
 * the top-level Order Status does not. Milestones: Created/Paid/Shipped/
 * Delivered/Cancelled Time ('d/m/Y H:i:s'); TikTok exposes no completion
 * timestamp, so completed_date stays null (CONTEXT.md: completed_date).
 *
 * Effective Price: TikTok gives only subtotals — the line carries the
 * exact `SKU Subtotal Before Discount − SKU Seller Discount` as its
 * line_total (the platform subsidy never reduces it, CONTEXT.md:
 * Effective Price); the per-unit price is that total floored per unit
 * when it does not divide evenly (ADR 0015: no invented satang).
 */
class TiktokOrderImporter extends MarketplaceOrderImporter
{
    private const STATUS_MAP = [
        'รอจัดส่ง' => OrderStatus::AwaitingPack,
        'อยู่ระหว่างขนส่ง' => OrderStatus::InTransit,
        'จัดส่งสำเร็จ' => OrderStatus::Delivered,
        'เสร็จสมบูรณ์' => OrderStatus::Completed,
        'ยกเลิกแล้ว' => OrderStatus::Cancelled,
    ];

    /** TikTok gives attribution and reason as two columns (CONTEXT.md). */
    private const CANCELLED_BY_MAP = [
        'User' => CancelledBy::Buyer,
        'Seller' => CancelledBy::Seller,
        'System' => CancelledBy::System,
    ];

    /**
     * Raw reason → canonical bucket, exactly the values observed in the
     * reference export; `other` only via these explicit entries (ADR 0005).
     */
    private const CANCEL_REASON_MAP = [
        'ไม่ต้องการแล้ว' => CancelReasonCategory::BuyerChangedMind,
        'มีราคาที่ดีกว่า' => CancelReasonCategory::BuyerChangedMind,
        'ค่าจัดส่งแพง' => CancelReasonCategory::BuyerChangedMind,
        'สินค้าหมด' => CancelReasonCategory::OutOfStock,
        'ข้อผิดพลาดในการกำหนดราคา' => CancelReasonCategory::PricingError,
        'การจัดส่งพัสดุไม่สำเร็จ' => CancelReasonCategory::FailedDelivery,
        'จำเป็นต้องเปลี่ยนที่อยู่จัดส่ง' => CancelReasonCategory::AddressChange,
        'ต้องการเปลี่ยนวิธีการชำระเงิน' => CancelReasonCategory::PaymentIssue,
        'วิธีการชำระเงินไม่พร้อมใช้งาน' => CancelReasonCategory::PaymentIssue,
        'ลูกค้าปล่อยไว้จนเกินกำหนดชำระเงิน' => CancelReasonCategory::PaymentIssue,
        'จำเป็นต้องเปลี่ยนสีหรือขนาด' => CancelReasonCategory::Other,
        'ต้องการใช้คูปองในการสั่งซื้อ' => CancelReasonCategory::Other,
        'ผู้ขายไม่ตอบคำถาม' => CancelReasonCategory::Other,
    ];

    public function map(string $nativeStatus): OrderStatus
    {
        return self::STATUS_MAP[$nativeStatus]
            ?? throw new UnmappedPlatformStatusException("ระบบไม่รองรับ — unmapped TikTok substatus [{$nativeStatus}].");
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        $qtyCell = $this->cell($row, 'Quantity');
        $qty = is_numeric($qtyCell) ? (int) $qtyCell : 0;

        $lineTotal = $this->moneyCell($row, 'SKU Subtotal Before Discount')
            ->subtract($this->moneyCell($row, 'SKU Seller Discount'));
        $unitPrice = $qty > 0 ? Money::fromSatang(intdiv($lineTotal->satang, $qty)) : Money::fromSatang(0);

        return [
            'order_id' => $this->cell($row, 'Order ID'),
            'native_status' => $this->cell($row, 'Order Substatus'),
            'platform_sku' => $this->cell($row, 'Seller SKU'),
            'qty' => $qty,
            'unit_price' => $unitPrice->toBaht(),
            'line_total' => $lineTotal,
            'milestones' => [
                'created_date' => $this->parseBangkokTime($row['Created Time'] ?? null, 'Created Time'),
                'paid_date' => $this->parseBangkokTime($row['Paid Time'] ?? null, 'Paid Time'),
                'shipped_date' => $this->parseBangkokTime($row['Shipped Time'] ?? null, 'Shipped Time'),
                'delivered_date' => $this->parseBangkokTime($row['Delivered Time'] ?? null, 'Delivered Time'),
                'cancelled_date' => $this->parseBangkokTime($row['Cancelled Time'] ?? null, 'Cancelled Time'),
            ],
            'tracking_number' => $this->cell($row, 'Tracking ID'),
            // PII minimised: the buyer username only — recipient, phone,
            // and address never leave the file.
            'buyer_name' => $this->cell($row, 'Buyer Username'),
            'cancelled_by' => $this->cancelledBy($this->cell($row, 'Cancel By')),
            'cancel_reason_category' => $this->cancelCategory($this->cell($row, 'Cancel Reason')),
            'cancel_reason_source' => $this->cell($row, 'Cancel Reason') ?: null,
        ];
    }

    private function cancelledBy(string $raw): ?CancelledBy
    {
        if ($raw === '') {
            return null;
        }

        return self::CANCELLED_BY_MAP[$raw]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped TikTok Cancel By [{$raw}].");
    }

    private function cancelCategory(string $raw): ?CancelReasonCategory
    {
        if ($raw === '') {
            return null;
        }

        return self::CANCEL_REASON_MAP[$raw]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped TikTok cancel reason [{$raw}].");
    }

    /**
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        // '05/06/2026 12:53:02' (the trailing tab is trimmed upstream).
        return ['d/m/Y H:i:s'];
    }
}
