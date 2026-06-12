<?php

namespace App\Imports;

use App\Enums\OrderStatus;
use App\Support\Money;

/**
 * Lazada order export (reference: `ref doc/lazada/`, 77 English-header
 * columns, **one row per unit** — orderItemId — so qty is 1 per row and
 * the core's aggregation rebuilds the line quantities). Status is
 * item-level: partially-cancelled orders mix `canceled` rows with live
 * ones, so consolidation drops the cancelled items and the line diff
 * RELEASEs them on re-import (CONTEXT.md: Reserved Stock). Milestones:
 * createTime → created_date, deliveredDate → delivered_date (Lazada's
 * payout anchor); no paid/completed timestamp columns (CONTEXT.md:
 * completed_date). Effective Price = unitPrice + sellerDiscountTotal
 * (stored negative); the platform-funded subsidy never reduces it
 * (CONTEXT.md: Effective Price).
 *
 * Pre-ship statuses (pending / ready-to-ship) did not occur in the
 * reference export, so their exact spelling is unverified — they are
 * deliberately unmapped until observed (ADR 0005); same for `Lost by 3PL`,
 * which fits no canonical state.
 */
class LazadaOrderImporter extends MarketplaceOrderImporter
{
    private const STATUS_MAP = [
        'shipped' => OrderStatus::InTransit,
        'confirmed' => OrderStatus::Completed,
        'canceled' => OrderStatus::Cancelled,
        // Whole package failed delivery / returned to sender.
        'Package Returned' => OrderStatus::Bounced,
        // A post-delivery buyer return is a Return entity (ADR 0006) — the
        // parent order legitimately stays สำเร็จ.
        'returned' => OrderStatus::Completed,
    ];

    public function map(string $nativeStatus): OrderStatus
    {
        return self::STATUS_MAP[$nativeStatus]
            ?? throw new UnmappedPlatformStatusException("ระบบไม่รองรับ — unmapped Lazada status [{$nativeStatus}].");
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        $unitPrice = Money::fromBaht($this->cell($row, 'unitPrice') ?: '0');
        $sellerDiscount = $this->cell($row, 'sellerDiscountTotal');

        if ($sellerDiscount !== '') {
            $unitPrice = $unitPrice->add(Money::fromBaht($sellerDiscount));
        }

        return [
            'order_id' => $this->cell($row, 'orderNumber'),
            'native_status' => $this->cell($row, 'status'),
            'platform_sku' => $this->cell($row, 'sellerSku'),
            'qty' => 1,
            'unit_price' => $unitPrice->toBaht(),
            'milestones' => [
                'created_date' => $this->parseBangkokTime($row['createTime'] ?? null, 'createTime'),
                'delivered_date' => $this->parseBangkokTime($row['deliveredDate'] ?? null, 'deliveredDate'),
            ],
            'tracking_number' => $this->cell($row, 'trackingCode'),
            // PII minimised: Lazada already masks customerName; recipient
            // details and addresses never leave the file.
            'buyer_name' => $this->cell($row, 'customerName'),
        ];
    }

    /**
     * Item-level statuses: the live (non-cancelled) rows define the order;
     * only a fully-cancelled order mirrors as ยกเลิก.
     */
    protected function consolidateOrder(array $rows): array
    {
        $live = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['status'] !== OrderStatus::Cancelled,
        ));

        if ($live === []) {
            return ['header' => $rows[0], 'lines' => $rows];
        }

        return ['header' => $live[0], 'lines' => $live];
    }

    /**
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        // '03 Jun 2026 20:41'
        return ['d M Y H:i'];
    }
}
