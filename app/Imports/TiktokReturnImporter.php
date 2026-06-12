<?php

namespace App\Imports;

use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Support\Money;

/**
 * TikTok return/refund export (reference: `ref doc/tiktok/All return
 * refund tiktok.csv`, 25 English headers, CSV, one row per SKU line with
 * `Return Quantity` — money cells carry a ฿ marker, timestamps a trailing
 * tab). Closure (ยกเลิกการคืน, ADR 0006): `Request Canceled` /
 * `Refund rejected`. A `Completed` return-and-refund case maps to the
 * courier-delivered state — only the physical Inbound Scan ever sets
 * รับของกลับแล้ว; a `Completed` refund-only case is terminal with no
 * goods journey. Refund per line sums per case; `Refund Time` carries
 * the refund fact + timestamp. Raw Return Reason stored as-is — fault
 * bucketing is Phase 8 (CONTEXT.md: Return Reason — this file also
 * carries pre-shipment cancellation reasons; those rows still arrive as
 * TikTok return cases and close terminally with no stock/rollup effect).
 */
class TiktokReturnImporter extends MarketplaceReturnImporter
{
    private const TYPE_MAP = [
        'Return and refund' => ReturnType::ReturnAndRefund,
        'Refund only' => ReturnType::RefundOnly,
    ];

    protected function normalizeReturnRow(array $row, int $rowNumber): array
    {
        $type = $this->mapType($this->cell($row, 'Return Type'));
        $qty = $this->cell($row, 'Return Quantity');
        $unitPrice = $this->moneyCell($row, 'Return unit price');
        $qtyInt = is_numeric($qty) ? (int) $qty : 0;

        return [
            'return_id' => $this->cell($row, 'Return Order ID'),
            'return_type' => $type,
            'sub_status' => $this->mapSubStatus(
                $type,
                $this->cell($row, 'Return Status'),
                $this->cell($row, 'Return Sub Status'),
            ),
            'platform_order_id' => $this->cell($row, 'Order ID'),
            'platform_sku' => $this->cell($row, 'Seller SKU'),
            'qty' => $qtyInt,
            'return_reason' => $this->cell($row, 'Return Reason') ?: null,
            'buyer_note' => $this->cell($row, 'Buyer Note') ?: null,
            // Per-LINE amount — caseRefundAmount() sums the rows.
            'refund_amount' => $unitPrice->multiply(max(1, $qtyInt)),
            'tracking_number' => $this->cell($row, 'Return Logistics Tracking ID') ?: null,
            'requested_at' => $this->parseBangkokTime($row['Time Requested'] ?? null, 'Time Requested'),
            'refunded_at' => $this->parseBangkokTime($row['Refund Time'] ?? null, 'Refund Time'),
        ];
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    protected function caseRefundAmount(array $rows): ?Money
    {
        $total = Money::fromSatang(0);

        foreach ($rows as $row) {
            $amount = $row['refund_amount'];

            if ($amount instanceof Money) {
                $total = $total->add($amount);
            }
        }

        return $total;
    }

    private function mapType(string $raw): ReturnType
    {
        return self::TYPE_MAP[$raw]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped TikTok Return Type [{$raw}].");
    }

    private function mapSubStatus(ReturnType $type, string $status, string $subStatus): ReturnSubStatus
    {
        if ($subStatus === 'Request Canceled' || $status === 'Refund rejected') {
            return ReturnSubStatus::Closed;
        }

        if ($status === 'Completed') {
            // Goods were delivered back per the platform — the scan still
            // gates the stock; a refund-only case has no goods journey.
            return $type === ReturnType::RefundOnly
                ? ReturnSubStatus::Closed
                : ReturnSubStatus::CourierClaimsDelivered;
        }

        throw new RowImportException("ระบบไม่รองรับ — unmapped TikTok Return Status [{$status}] / Sub Status [{$subStatus}].");
    }

    /**
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        // '07/05/2026 20:58:44' (the trailing tab is trimmed upstream).
        return ['d/m/Y H:i:s'];
    }
}
