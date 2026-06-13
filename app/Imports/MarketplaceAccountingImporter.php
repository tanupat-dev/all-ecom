<?php

namespace App\Imports;

use App\Actions\Accounting\UpsertAccountingCycle;
use App\Enums\AccountingLineCategory;
use App\Models\Order;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The shared half of every platform accounting importer (ROADMAP Phase 6,
 * ADR 0007/0020): a concrete importer contributes only the platform's
 * column extraction (normalizeRow) — the gross-sale income line, the signed
 * fee/refund lines, the statement cycle, the settlement date, and the file's
 * own transferred-total when it prints one. This base does the channel-
 * agnostic work: the fail-loud reconciliation cross-check, the tenant-scoped
 * Order match, the cycle-aware upsert through UpsertAccountingCycle, and the
 * no-null-overwrite Settlement Date.
 *
 * The file's data sheet is not the first sheet and its header sits below a
 * preamble, so it opts into HasSheetLayout.
 *
 * Per-row fail-loud checks (reconciliation, order match) live in mapRow — the
 * pipeline's only per-row error channel (ADR 0005): a held row leaves the
 * ImportJob CompletedWithErrors with the other orders still written, whereas a
 * throw from upsertChunk would fail the whole file. The grouping and the
 * cross-chunk accumulation a split order needs live in upsertChunk.
 */
abstract class MarketplaceAccountingImporter extends PlatformFileImporter implements HasSheetLayout
{
    /**
     * Accumulated line set per (order_id|statement_cycle) seen so far THIS run.
     * A single order's lines can split across two 500-row chunks (Lazada's
     * one-line-per-row journal); each flush re-upserts the cycle with the FULL
     * accumulated set, so a split order is never half-written. The same-cycle
     * REPLACE of ADR 0007 is the right semantics across separate runs (re-
     * uploading a file) — within one run a split order must accumulate.
     *
     * @var array<string, array{order: Order, statement_cycle: string, settlement_date: ?DateTimeInterface, lines: list<array{source_field: string, category: AccountingLineCategory, amount: Money}>}>
     */
    private array $accumulated = [];

    public function __construct(
        private readonly UpsertAccountingCycle $upsertCycle,
    ) {}

    /**
     * Extract one platform row into the channel-agnostic accounting shape.
     * Shopee/TikTok produce MANY lines from one wide row; Lazada produces ONE
     * line per row. transferred_total is the file's stated net-transferred
     * figure for the order when the file exposes one (Shopee does), else null.
     *
     * @param  array<string, mixed>  $row
     * @return array{order_id: string, statement_cycle: string, settlement_date: ?DateTimeInterface, lines: list<array{source_field: string, category: AccountingLineCategory, amount: Money}>, transferred_total?: ?Money}
     */
    abstract protected function normalizeRow(array $row, int $rowNumber): array;

    public function mapRow(array $row, int $rowNumber): array
    {
        $normalized = $this->normalizeRow($row, $rowNumber);

        $orderId = $normalized['order_id'];
        $cycle = $normalized['statement_cycle'];

        if ($orderId === '') {
            throw new RowImportException('ระบบไม่รองรับ — an accounting row with no order id cannot be imported.');
        }

        if ($cycle === '') {
            // No cycle id and no settlement/period to key one (ADR 0007) — held.
            throw new RowImportException("ระบบไม่รองรับ — order [{$orderId}] has no statement cycle / settlement date to key on.");
        }

        // Income-leg reconciliation (ADR 0020): the signed lines must sum to the
        // figure the file itself prints as transferred — a column the mapping
        // missed makes this fail loud (ADR 0005), within a 1-satang rounding
        // allowance. Skipped where the platform prints no transferred-total.
        $transferred = $normalized['transferred_total'] ?? null;

        if ($transferred instanceof Money) {
            $sum = $this->sumLines($normalized['lines']);

            if (abs($sum->satang - $transferred->satang) > 1) {
                throw new RowImportException(sprintf(
                    'ระบบไม่รองรับ — order [%s]: categorised lines sum to %s but the file reports %s transferred — a fee column is unmapped.',
                    $orderId,
                    $sum->toBaht(),
                    $transferred->toBaht(),
                ));
            }
        }

        // Tenant-scoped Order match (ADR 0005/0011): an order id the file names
        // but the system has no Order for is held, never written.
        $order = $this->resolveOrder($orderId);

        return [
            'order' => $order,
            'order_id' => $orderId,
            'statement_cycle' => $cycle,
            'settlement_date' => $normalized['settlement_date'],
            'lines' => $normalized['lines'],
        ];
    }

    public function upsertChunk(array $chunk): void
    {
        $touched = [];

        foreach ($chunk as $row) {
            $order = $row['order'] ?? null;
            $cycle = $row['statement_cycle'] ?? null;
            $lines = $row['lines'] ?? null;

            if (! $order instanceof Order || ! is_string($cycle) || ! is_array($lines)) {
                throw new LogicException('accounting normalizeRow shape drifted.');
            }

            $key = $order->id.'|'.$cycle;
            $settlementDate = ($row['settlement_date'] ?? null) instanceof DateTimeInterface ? $row['settlement_date'] : null;

            if (! isset($this->accumulated[$key])) {
                $this->accumulated[$key] = [
                    'order' => $order,
                    'statement_cycle' => $cycle,
                    'settlement_date' => $settlementDate,
                    'lines' => [],
                ];
            }

            /** @var list<array{source_field: string, category: AccountingLineCategory, amount: Money}> $lines */
            $this->accumulated[$key]['lines'] = [...$this->accumulated[$key]['lines'], ...$lines];

            // First non-null settlement date wins (no-null-overwrite).
            if ($this->accumulated[$key]['settlement_date'] === null && $settlementDate !== null) {
                $this->accumulated[$key]['settlement_date'] = $settlementDate;
            }

            $touched[$key] = true;
        }

        // Re-upsert only the groups this chunk touched, each with its FULL
        // accumulated line set (UpsertAccountingCycle replaces the cycle's
        // lines, so this is idempotent and split-safe).
        foreach (array_keys($touched) as $key) {
            $group = $this->accumulated[$key];
            $order = $group['order'];

            $this->upsertCycle->handle($order, $group['statement_cycle'], $group['lines']);

            // Settlement Date: fill only — never clobber an existing date with
            // null (ADR 0004 milestone spirit).
            if ($group['settlement_date'] instanceof DateTimeInterface && $order->settlement_date === null) {
                $order->settlement_date = Carbon::instance($group['settlement_date']);
                $order->save();
            }
        }
    }

    /**
     * @param  list<array{source_field: string, category: AccountingLineCategory, amount: Money}>  $lines
     */
    private function sumLines(array $lines): Money
    {
        $sum = Money::fromSatang(0);

        foreach ($lines as $line) {
            $sum = $sum->add($line['amount']);
        }

        return $sum;
    }

    private function resolveOrder(string $orderId): Order
    {
        // (tenant, shop, platform_order_id) is the Order's natural key — shop()
        // is already tenant-scoped, and BelongsToTenant scopes this query, so a
        // cross-tenant order id can never resolve.
        $order = Order::query()
            ->where('shop_id', $this->shop()->id)
            ->where('platform_order_id', $orderId)
            ->first();

        if ($order === null) {
            throw new RowImportException("ระบบไม่รองรับ — ไม่พบคำสั่งซื้อ [{$orderId}] สำหรับร้านนี้ (accounting import).");
        }

        return $order;
    }
}
