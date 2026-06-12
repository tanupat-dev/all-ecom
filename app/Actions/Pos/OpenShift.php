<?php

namespace App\Actions\Pos;

use App\Enums\ShiftStatus;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Opens a Shift on a Register with the counted opening float
 * (CONTEXT.md: Shift). Gated by pos.open_shift (ADR 0012).
 */
class OpenShift
{
    public function handle(Register $register, Money $openingFloat): Shift
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->checkPermissionTo('pos.open_shift')) {
            throw new AuthorizationException('Opening a Shift requires the pos.open_shift permission.');
        }

        $alreadyOpen = Shift::query()
            ->where('register_id', $register->id)
            ->where('status', ShiftStatus::Open)
            ->exists();

        if ($alreadyOpen) {
            throw new LogicException("Register [{$register->name}] already has an open Shift — close it first.");
        }

        return Shift::query()->create([
            'register_id' => $register->id,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_float' => $openingFloat,
        ]);
    }
}
