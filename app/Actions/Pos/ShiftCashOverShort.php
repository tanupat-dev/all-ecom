<?php

namespace App\Actions\Pos;

use App\Models\Shift;
use App\Support\Money;
use LogicException;

/**
 * The Shift's Cash Over/Short as a signed P&L line (CONTEXT.md: Shift —
 * over_short; ADR 0015). over_short = counted_cash − expected_cash and is
 * already signed the way the income statement reads it: a net SHORTAGE is
 * NEGATIVE (an other-expense), a net OVERAGE is POSITIVE (other-income).
 *
 * The daily / period rollup (#71) sums this into the combined P&L alongside
 * each Order's net (ComputePosOrderNet); this Action just exposes the
 * computable, sign-correct value so the rollup never re-derives the sign.
 *
 * Defined only AFTER a blind close (CloseShift) has set over_short. On an
 * open Shift the figure does not yet exist, so this fails loud rather than
 * defaulting to a misleading zero.
 */
class ShiftCashOverShort
{
    public function handle(Shift $shift): Money
    {
        return $shift->over_short
            ?? throw new LogicException('Cash Over/Short is defined only after the Shift is closed (blind close sets over_short).');
    }
}
