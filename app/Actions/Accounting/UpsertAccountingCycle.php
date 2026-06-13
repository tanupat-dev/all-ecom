<?php

namespace App\Actions\Accounting;

use App\Actions\Claims\FlagShippingOverchargeClaim;
use App\Enums\AccountingLineCategory;
use App\Enums\PlatformType;
use App\Models\AccountingEntryLine;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE single write path into an Order's Accounting Entry (CONTEXT.md:
 * Accounting Entry; ADR 0007). Import is cycle-aware: re-importing the **same**
 * statement cycle replaces that cycle's lines (idempotent — no double-count),
 * while a **different** cycle's lines are left untouched (append). Actual Net —
 * the denormalized sum across **all** the Order's cycles — is recomputed in the
 * same transaction, so the read path never SUMs lines at report time.
 *
 * Marketplace Orders only: a POS Order has no Accounting Entry — its P&L is
 * computed directly (Payment − COGS), so this Action refuses one.
 */
class UpsertAccountingCycle
{
    public function __construct(
        private readonly ComputeReconciliationStatus $computeReconciliationStatus,
        private readonly FlagShippingOverchargeClaim $flagShippingOverchargeClaim,
    ) {}

    /**
     * @param  array<int, array{source_field: string, category: AccountingLineCategory, amount: Money}>  $lines
     */
    public function handle(Order $order, string $statementCycle, array $lines): void
    {
        if ($order->platform_type === PlatformType::Pos) {
            throw new InvalidArgumentException('A POS Order has no Accounting Entry — its P&L is computed directly (CONTEXT.md).');
        }

        DB::transaction(function () use ($order, $statementCycle, $lines): void {
            // Cycle is the replace boundary (ADR 0007): clear only this cycle's
            // lines, then re-insert — other cycles for the Order are untouched.
            AccountingEntryLine::query()
                ->where('order_id', $order->id)
                ->where('statement_cycle', $statementCycle)
                ->delete();

            foreach ($lines as $line) {
                AccountingEntryLine::query()->create([
                    'order_id' => $order->id,
                    'statement_cycle' => $statementCycle,
                    'source_field' => $line['source_field'],
                    'category' => $line['category'],
                    'amount' => $line['amount'],
                ]);
            }

            // Recompute Actual Net = Σ of every line across every cycle, in
            // satang (integer). Denormalized onto the Order as the read path.
            $totalSatang = (int) AccountingEntryLine::query()
                ->where('order_id', $order->id)
                ->sum('amount');

            $order->actual_net = Money::fromSatang($totalSatang);
            $order->save();

            // Re-grade Reconciliation Status in the SAME transaction now that
            // Actual Net moved — a late cycle's deduction can flip a previously
            // paid_ok Order to paid_mismatch (ADR 0007), the desired behaviour.
            $this->computeReconciliationStatus->handle($order);

            // Auto-flag a shipping_overcharge Claim when the courier billed the
            // seller above the catalogue-expected rate (Issue #85, ADR 0022).
            // Idempotent inside the flag action — re-importing a cycle never
            // creates a second Claim. In the SAME transaction so the Claim and
            // accounting commit or roll back together (mirrors UpsertReturn #80).
            $this->flagShippingOverchargeClaim->handle($order);
        });
    }
}
