<?php

namespace App\Actions\Pos;

use App\Enums\CashMovementType;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\ShiftCashMovement;
use App\Support\Money;
use InvalidArgumentException;
use LogicException;

/**
 * Records a Paid-in / Paid-out BEFORE the cash physically moves, so
 * expected_cash stays truthful (CONTEXT.md: Shift).
 */
class RecordCashMovement
{
    public function handle(Shift $shift, CashMovementType $type, Money $amount, string $reason): ShiftCashMovement
    {
        if ($shift->status !== ShiftStatus::Open) {
            throw new LogicException('Cash moves only on an open Shift.');
        }

        if ($amount->isNegative() || $amount->isZero()) {
            throw new InvalidArgumentException('A cash movement amount must be positive — the direction is the type.');
        }

        return ShiftCashMovement::query()->create([
            'shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }
}
