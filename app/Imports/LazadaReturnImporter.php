<?php

namespace App\Imports;

use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Support\Money;

/**
 * Lazada return/refund export (reference: `ref doc/lazada/All return
 * refund lazada.xlsx`, 19 English headers, one row per Return Item — qty
 * is 1 per row and the core aggregates). The goods journey lives in
 * `Logistic Status`; the case `Status` only says Refunded/ReturnClosed —
 * and the real data shows ReturnClosed on completed returns too, so it is
 * a closure (ยกเลิกการคืน) ONLY when no goods journey ever started.
 * `Refunded` with no goods journey = refund without return → refund_only
 * (CONTEXT.md: Return). Lazada states the refund as a status with no
 * timestamp, so `refunded` is passed explicitly and refunded_at stays
 * null. Raw Return Reason is stored as-is — fault bucketing is Phase 8.
 */
class LazadaReturnImporter extends MarketplaceReturnImporter
{
    private const CASE_STATUSES = ['Refunded', 'ReturnClosed'];

    /** The goods-journey table (ADR 0005) — '' = no shipment started. */
    private const LOGISTIC_MAP = [
        'รอคุณส่งสินค้าคืนที่จุดส่งคืน/ผู้ขนส่งกำลังเข้ารับสินค้าคืน' => ReturnSubStatus::AwaitingBuyerShipment,
        'อยู่ระหว่างการจัดส่งคืนร้านค้า' => ReturnSubStatus::InTransitBack,
        'จัดส่งคืนสินค้าถึงร้านค้าสำเร็จ' => ReturnSubStatus::CourierClaimsDelivered,
    ];

    protected function normalizeReturnRow(array $row, int $rowNumber): array
    {
        $status = $this->cell($row, 'Status');
        $logistic = $this->cell($row, 'Logistic Status');

        if (! in_array($status, self::CASE_STATUSES, true)) {
            throw new RowImportException("ระบบไม่รองรับ — unmapped Lazada return status [{$status}].");
        }

        $refund = $this->cell($row, 'Refund Amount');

        return [
            'return_id' => $this->cell($row, 'Return Order ID'),
            'return_type' => $logistic === '' && $status === 'Refunded' ? ReturnType::RefundOnly : ReturnType::ReturnAndRefund,
            'sub_status' => $this->mapSubStatus($status, $logistic),
            'platform_order_id' => $this->cell($row, 'Order ID'),
            'platform_sku' => $this->cell($row, 'Seller SKU ID'),
            'qty' => 1,
            'return_reason' => $this->cell($row, 'Return Reason') ?: null,
            'buyer_note' => null,
            'refund_amount' => $refund !== '' ? Money::fromBaht($refund) : null,
            'tracking_number' => $this->cell($row, 'Tracking Number') ?: null,
            'requested_at' => $this->parseBangkokTime($row['Return Order Date'] ?? null, 'Return Order Date'),
            'refunded_at' => null,
            // `Refunded` states it outright; a (ReturnClosed) case whose
            // goods were delivered back is a completed return — Lazada
            // refunds on return delivery. Only a closed case with no
            // goods journey stays unrefunded (abandoned/rejected — the
            // export cannot distinguish, so we never claim money moved).
            'refunded' => $status === 'Refunded'
                || $logistic === 'จัดส่งคืนสินค้าถึงร้านค้าสำเร็จ',
        ];
    }

    private function mapSubStatus(string $status, string $logistic): ReturnSubStatus
    {
        if ($logistic === '') {
            // No goods journey ever started: a closed case is the
            // Platform closure (ADR 0006); a refunded one is refund-only
            // and carries no journey — Closed fits both terminally.
            return ReturnSubStatus::Closed;
        }

        return self::LOGISTIC_MAP[$logistic]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped Lazada logistic status [{$logistic}].");
    }
}
