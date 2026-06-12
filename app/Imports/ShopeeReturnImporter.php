<?php

namespace App\Imports;

use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Support\Money;

/**
 * Shopee return/refund export (reference: `ref doc/shopee/Order.return_
 * refund_cancel shopee/` — the `.xls` is really an xlsx; 48 Thai headers
 * with duplicate address-block names the importer never reads). The case
 * state spreads across two columns: the main สถานะการคืนเงินหรือคืนสินค้า
 * (closure lives here — ยกเลิกคำขอ → ยกเลิกการคืน, ADR 0006) and the
 * goods-journey สถานะการส่งสินค้าคืน. Nothing ever maps to รับของกลับแล้ว
 * — only the physical Inbound Scan sets that (CONTEXT.md: Stock Return).
 */
class ShopeeReturnImporter extends MarketplaceReturnImporter
{
    /** The offer column — the return_type table (ADR 0005). */
    private const TYPE_MAP = [
        'คืนเงินและคืนสินค้า' => ReturnType::ReturnAndRefund,
    ];

    /** Main statuses observed in the reference export. */
    private const MAIN_STATUSES = [
        'รอการตรวจสอบ',
        'คืนเงินแล้ว',
        'ยกเลิกคำขอ',
    ];

    /** The goods-journey column; '' = the buyer has not shipped yet. */
    private const SHIP_STATUS_MAP = [
        '' => ReturnSubStatus::AwaitingBuyerShipment,
        'จัดส่งสินค้าคืนสำเร็จ' => ReturnSubStatus::CourierClaimsDelivered,
    ];

    protected function normalizeReturnRow(array $row, int $rowNumber): array
    {
        $refund = $this->cell($row, 'จำนวนเงินคืนทั้งหมด');
        $qty = $this->cell($row, 'จำนวนสินค้าคืน');

        return [
            'return_id' => $this->cell($row, 'หมายเลขคำขอคืนเงิน/คืนสินค้า'),
            'return_type' => $this->mapType($this->cell($row, 'ข้อเสนอการคืนเงิน/คืนสินค้า')),
            'sub_status' => $this->mapSubStatus(
                $this->cell($row, 'สถานะการคืนเงินหรือคืนสินค้า'),
                $this->cell($row, 'สถานะการส่งสินค้าคืน'),
            ),
            'platform_order_id' => $this->cell($row, 'หมายเลขคำสั่งซื้อ'),
            'platform_sku' => $this->cell($row, 'เลข SKU'),
            'qty' => is_numeric($qty) ? (int) $qty : 0,
            'return_reason' => $this->cell($row, 'เหตุผลในการขอคืนสินค้า') ?: null,
            'buyer_note' => $this->cell($row, 'หมายเหตุในการคืนสินค้า') ?: null,
            'refund_amount' => $refund !== '' ? Money::fromBaht($refund) : null,
            'tracking_number' => $this->cell($row, 'หมายเลขติดตามพัสดุสำหรับส่งคืน') ?: null,
            'requested_at' => $this->parseBangkokTime($row['เวลายื่นคำขอคืนเงิน/คืนสินค้า'] ?? null, 'เวลายื่นคำขอคืนเงิน/คืนสินค้า'),
            'refunded_at' => $this->parseBangkokTime($row['เวลาที่คืนเงิน'] ?? null, 'เวลาที่คืนเงิน'),
        ];
    }

    private function mapType(string $offer): ReturnType
    {
        return self::TYPE_MAP[$offer]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped Shopee return offer [{$offer}].");
    }

    private function mapSubStatus(string $mainStatus, string $shipStatus): ReturnSubStatus
    {
        if (! in_array($mainStatus, self::MAIN_STATUSES, true)) {
            throw new RowImportException("ระบบไม่รองรับ — unmapped Shopee return status [{$mainStatus}].");
        }

        if ($mainStatus === 'ยกเลิกคำขอ') {
            return ReturnSubStatus::Closed;
        }

        return self::SHIP_STATUS_MAP[$shipStatus]
            ?? throw new RowImportException("ระบบไม่รองรับ — unmapped Shopee return shipping status [{$shipStatus}].");
    }
}
