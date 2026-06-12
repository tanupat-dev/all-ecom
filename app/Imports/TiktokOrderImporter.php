<?php

namespace App\Imports;

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
        ];
    }

    /**
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        // '05/06/2026 12:53:02' (the trailing tab is trimmed upstream).
        return ['d/m/Y H:i:s'];
    }

    /**
     * A TikTok money cell: a plain baht number, defensively stripped of a
     * currency marker / thousands commas; empty = zero.
     *
     * @param  array<string, mixed>  $row
     */
    private function moneyCell(array $row, string $header): Money
    {
        $value = str_replace(['THB', ',', ' '], '', $this->cell($row, $header));

        return Money::fromBaht($value === '' ? '0' : $value);
    }
}
