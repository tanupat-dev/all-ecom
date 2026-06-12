<?php

namespace App\Imports;

use App\Actions\Listings\ResolvePlatformSku;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Enums\CancelledBy;
use App\Enums\CancelReasonCategory;
use App\Enums\OrderStatus;
use App\Listings\UnresolvedPlatformSkuException;
use App\Models\ImportJob;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;

/**
 * The shared half of every platform order importer (ROADMAP Phase 4):
 * fail-loud SKU resolution per row (ADR 0005), group-by-order upsert
 * through the import core, and merge tracking for an order whose rows
 * split across two pipeline chunks. A concrete importer contributes the
 * platform's column extraction (normalizeRow) and its versioned status
 * mapping table (map). The target Shop arrives via the ImportJob context
 * (shop_id) — the export file itself cannot say which Shop it belongs to.
 */
abstract class MarketplaceOrderImporter implements Importer, ImportJobAware, PlatformStatusMapper
{
    private ?ImportJob $importJob = null;

    private ?Shop $shop = null;

    /** @var array<string, true> */
    private array $upsertedOrders = [];

    public function __construct(
        private readonly ResolvePlatformSku $resolveSku,
        private readonly ImportMarketplaceOrder $importOrder,
    ) {}

    /**
     * Extract one platform row into the channel-agnostic per-row shape:
     * order_id, native_status, platform_sku, qty, unit_price (baht string),
     * milestones (field => DateTimeInterface|null), tracking_number,
     * buyer_name, and optionally an exact line_total for exports that only
     * give subtotals (see NormalizedOrder). Throw RowImportException for
     * anything unmappable.
     *
     * @param  array<string, mixed>  $row
     * @return array{order_id: string, native_status: string, platform_sku: string, qty: int, unit_price: string, milestones: array<string, DateTimeInterface|null>, tracking_number: ?string, buyer_name: ?string, line_total?: Money, cancelled_by?: ?CancelledBy, cancel_reason_category?: ?CancelReasonCategory, cancel_reason_source?: ?string}
     */
    abstract protected function normalizeRow(array $row, int $rowNumber): array;

    public function setImportJob(ImportJob $importJob): void
    {
        $this->importJob = $importJob;
    }

    public function mapRow(array $row, int $rowNumber): array
    {
        $normalized = $this->normalizeRow($row, $rowNumber);

        if ($normalized['order_id'] === '') {
            throw new RowImportException('A row with no order id cannot be imported.');
        }

        if ($normalized['qty'] < 1) {
            throw new RowImportException("Unmapped qty for order [{$normalized['order_id']}] — expected a positive number.");
        }

        try {
            $variant = $this->resolveSku->handle($this->shop(), $normalized['platform_sku']);
        } catch (UnresolvedPlatformSkuException $e) {
            throw new RowImportException($e->getMessage());
        }

        return [
            'order_id' => $normalized['order_id'],
            'status' => $this->map($normalized['native_status']),
            'variant' => $variant,
            'qty' => $normalized['qty'],
            'unit_price' => Money::fromBaht($normalized['unit_price']),
            'line_total' => $normalized['line_total'] ?? null,
            'milestones' => $normalized['milestones'],
            'tracking_number' => $normalized['tracking_number'],
            'buyer_name' => $normalized['buyer_name'],
            'cancelled_by' => $normalized['cancelled_by'] ?? null,
            'cancel_reason_category' => $normalized['cancel_reason_category'] ?? null,
            'cancel_reason_source' => $normalized['cancel_reason_source'] ?? null,
        ];
    }

    public function upsertChunk(array $chunk): void
    {
        $byOrder = [];

        foreach ($chunk as $row) {
            $orderId = $row['order_id'];

            if (! is_string($orderId)) {
                throw new LogicException('normalizeRow shape drifted.');
            }

            $byOrder[$orderId][] = $row;
        }

        foreach ($byOrder as $orderId => $rows) {
            ['header' => $first, 'lines' => $rows] = $this->consolidateOrder($rows);
            $status = $first['status'];
            $milestones = $first['milestones'];
            $tracking = $first['tracking_number'];
            $buyer = $first['buyer_name'];
            $cancelledBy = $first['cancelled_by'] ?? null;
            $cancelCategory = $first['cancel_reason_category'] ?? null;
            $cancelSource = $first['cancel_reason_source'] ?? null;

            if (! $status instanceof OrderStatus || ! is_array($milestones)) {
                throw new LogicException('normalizeRow shape drifted.');
            }

            $lines = [];

            foreach ($rows as $row) {
                $variant = $row['variant'];
                $qty = $row['qty'];
                $price = $row['unit_price'];

                if (! $variant instanceof Variant || ! is_int($qty) || ! $price instanceof Money) {
                    throw new LogicException('normalizeRow shape drifted.');
                }

                $line = ['variant' => $variant, 'qty' => $qty, 'unit_price' => $price];

                if (($row['line_total'] ?? null) instanceof Money) {
                    $line['line_total'] = $row['line_total'];
                }

                $lines[] = $line;
            }

            /** @var array<string, DateTimeInterface|null> $milestones */
            $this->importOrder->handle($this->shop(), new NormalizedOrder(
                platformOrderId: $orderId,
                status: $status,
                lines: $lines,
                milestones: $milestones,
                trackingNumber: is_string($tracking) && $tracking !== '' ? $tracking : null,
                buyerName: is_string($buyer) && $buyer !== '' ? $buyer : null,
                cancelledBy: $cancelledBy instanceof CancelledBy ? $cancelledBy : null,
                cancelReasonCategory: $cancelCategory instanceof CancelReasonCategory ? $cancelCategory : null,
                cancelReasonSource: is_string($cancelSource) && $cancelSource !== '' ? $cancelSource : null,
            ), mergeLines: isset($this->upsertedOrders[$orderId]));

            $this->upsertedOrders[$orderId] = true;
        }
    }

    /**
     * Reduce one order's mapped rows to the header row its order-level
     * fields come from and the rows that become Order Lines. The default
     * suits order-level-status exports (Shopee); an item-level-status
     * export (Lazada) overrides to handle partial cancels.
     *
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @return array{header: array<string, mixed>, lines: non-empty-list<array<string, mixed>>}
     */
    protected function consolidateOrder(array $rows): array
    {
        return ['header' => $rows[0], 'lines' => $rows];
    }

    /**
     * The timestamp formats this platform's export writes, tried in order.
     *
     * @return non-empty-list<string>
     */
    protected function dateFormats(): array
    {
        return ['Y-m-d H:i:s', 'Y-m-d H:i'];
    }

    /**
     * Platform exports carry Thai wall-clock timestamps; we store UTC
     * (ROADMAP Phase 0: Time). An unparseable non-empty value is fail-loud,
     * never guessed (ADR 0005).
     */
    protected function parseBangkokTime(mixed $value, string $column): ?DateTimeImmutable
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '') {
            return null;
        }

        $bangkok = new DateTimeZone('Asia/Bangkok');

        foreach ($this->dateFormats() as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text, $bangkok);

            if ($parsed !== false) {
                return $parsed->setTimezone(new DateTimeZone('UTC'));
            }
        }

        throw new RowImportException("ระบบไม่รองรับ — unparseable timestamp [{$text}] in [{$column}].");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function cell(array $row, string $header): string
    {
        $value = $row[$header] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function shop(): Shop
    {
        if ($this->shop !== null) {
            return $this->shop;
        }

        $shopId = $this->importJob?->context['shop_id'] ?? null;

        if (! is_numeric($shopId)) {
            throw new LogicException('A marketplace order import needs a shop_id in its ImportJob context.');
        }

        return $this->shop = Shop::query()->findOrFail((int) $shopId);
    }
}
