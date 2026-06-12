<?php

namespace App\Actions\Pos;

use App\Enums\CashMovementType;
use App\Enums\ShiftStatus;
use App\Enums\TenderType;
use App\Models\Order;
use App\Models\Shift;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Blind close (CONTEXT.md: Shift): the Cashier counts FIRST; only then is
 * expected_cash derived and over_short = counted − expected stored —
 * the Cash Over/Short figure the P&L reads at Phase 6.
 *
 * expected_cash = opening_float + cash sales (net of change)
 *               + paid-in − paid-out − cash refunds.
 * The cash-sale / cash-refund terms read the Order Payment Lines of this
 * Shift (wired by the #26 checkout slice; until orders can carry
 * payments those terms are genuinely zero).
 */
class CloseShift
{
    public function handle(Shift $shift, Money $countedCash): Shift
    {
        if ($shift->status !== ShiftStatus::Open) {
            throw new LogicException('Only an open Shift can be closed.');
        }

        return DB::transaction(function () use ($shift, $countedCash): Shift {
            $expected = ($shift->opening_float ?? Money::fromSatang(0))
                ->add($this->cashMovementsNet($shift))
                ->add($this->cashSalesNet($shift));

            $shift->update([
                'status' => ShiftStatus::Closed,
                'closed_at' => now(),
                'counted_cash' => $countedCash,
                'expected_cash' => $expected,
                'over_short' => $countedCash->subtract($expected),
            ]);

            return $shift;
        });
    }

    private function cashMovementsNet(Shift $shift): Money
    {
        $net = Money::fromSatang(0);

        foreach ($shift->cashMovements()->get() as $movement) {
            $amount = $movement->amount ?? Money::fromSatang(0);
            $net = $movement->type === CashMovementType::PaidIn
                ? $net->add($amount)
                : $net->subtract($amount);
        }

        return $net;
    }

    /**
     * Cash-tender Payment Lines of this Shift's Orders, net of the change
     * handed back (change = tendered − total, covered by cash only). A
     * POS Return's negative cash Payment Lines subtract naturally — the
     * "− cash refunds" term (CONTEXT.md: Shift).
     */
    private function cashSalesNet(Shift $shift): Money
    {
        $net = Money::fromSatang(0);

        $orders = Order::query()
            ->where('shift_id', $shift->id)
            ->with('payments')
            ->get();

        foreach ($orders as $order) {
            $tendered = Money::fromSatang(0);
            $cash = Money::fromSatang(0);

            foreach ($order->payments as $payment) {
                $amount = $payment->amount ?? Money::fromSatang(0);
                $tendered = $tendered->add($amount);

                if ($payment->tender_type === TenderType::Cash) {
                    $cash = $cash->add($amount);
                }
            }

            $change = $tendered->subtract($order->total ?? Money::fromSatang(0));

            if ($change->isNegative()) {
                $change = Money::fromSatang(0);
            }

            $net = $net->add($cash->subtract($change));
        }

        return $net;
    }
}
