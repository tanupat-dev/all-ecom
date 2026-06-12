<?php

namespace App\Actions\Stock;

use App\Enums\StockAction;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * THE single write path into stock (ADR 0003/0013): appends one immutable
 * Stock Movement and updates the denormalized (Variant, Location) balance
 * **in the same transaction** — atomically, race-safe via an additive
 * upsert. Every stock change in the system goes through here.
 *
 * $qty is the positive magnitude — except RECOUNT, where it is the signed
 * recount delta. SHIP must state $reservedReleased explicitly: the amount
 * that order actually reserved (marketplace/social = line qty, POS = 0);
 * Reserved never goes negative because of it.
 */
class AppendStockMovement
{
    public function handle(
        Variant $variant,
        Location $location,
        StockAction $action,
        int $qty,
        ?Model $ref = null,
        ?string $note = null,
        ?int $reservedReleased = null,
    ): StockMovement {
        $this->guard($action, $qty, $reservedReleased);

        [$onHandDelta, $reservedDelta, $damagedDelta] = match ($action) {
            StockAction::Receive => [$qty, 0, 0],
            StockAction::Ship => [-$qty, -(int) $reservedReleased, 0],
            StockAction::Reserve => [0, $qty, 0],
            StockAction::Release => [0, -$qty, 0],
            StockAction::Damage => [-$qty, 0, $qty],
            StockAction::Restore => [$qty, 0, -$qty],
            StockAction::Recount => [$qty, 0, 0],
            StockAction::TransferOut => [-$qty, 0, 0],
            StockAction::TransferIn => [$qty, 0, 0],
        };

        $qtyDelta = $action === StockAction::Reserve || $action === StockAction::Release
            ? $reservedDelta
            : $onHandDelta;

        return DB::transaction(function () use ($variant, $location, $action, $ref, $note, $reservedReleased, $onHandDelta, $reservedDelta, $damagedDelta, $qtyDelta): StockMovement {
            $movement = StockMovement::query()->create([
                'variant_id' => $variant->id,
                'location_id' => $location->id,
                'action' => $action,
                'qty_delta' => $qtyDelta,
                'reserved_released' => $action === StockAction::Ship ? $reservedReleased : null,
                'ref_type' => $ref?->getMorphClass(),
                'ref_id' => $ref?->getKey(),
                'note' => $note,
            ]);

            $this->applyToBalance($variant, $location, $onHandDelta, $reservedDelta, $damagedDelta);

            return $movement;
        });
    }

    private function guard(StockAction $action, int $qty, ?int $reservedReleased): void
    {
        if ($action === StockAction::Recount) {
            if ($qty === 0) {
                throw new InvalidArgumentException('A RECOUNT with no delta records nothing — refuse it.');
            }
        } elseif ($qty <= 0) {
            throw new InvalidArgumentException("qty must be a positive magnitude for {$action->value} (only RECOUNT carries a signed delta).");
        }

        if ($action === StockAction::Ship) {
            if ($reservedReleased === null) {
                throw new InvalidArgumentException('SHIP is order-aware: state reservedReleased explicitly (marketplace/social = line qty, POS = 0).');
            }
            if ($reservedReleased < 0 || $reservedReleased > $qty) {
                throw new InvalidArgumentException('reservedReleased must be between 0 and the shipped qty.');
            }
        } elseif ($reservedReleased !== null) {
            throw new InvalidArgumentException("reservedReleased only applies to SHIP, not {$action->value}.");
        }
    }

    /**
     * Additive upsert — concurrency-safe (no read-modify-write) and inside
     * the caller's transaction, so the movement and the balance commit or
     * roll back together.
     */
    private function applyToBalance(Variant $variant, Location $location, int $onHandDelta, int $reservedDelta, int $damagedDelta): void
    {
        $tenant = app(TenantContext::class)->current()
            ?? throw new LogicException('A stock movement needs a tenant context.');

        DB::statement(<<<'SQL'
            insert into stock_balances
                (tenant_id, variant_id, location_id, on_hand, reserved, damaged, buffer, created_by, created_at, updated_at)
            values (?, ?, ?, ?, ?, ?, 0, ?, now(), now())
            on conflict (tenant_id, variant_id, location_id) do update set
                on_hand = stock_balances.on_hand + excluded.on_hand,
                reserved = stock_balances.reserved + excluded.reserved,
                damaged = stock_balances.damaged + excluded.damaged,
                updated_at = now()
            SQL, [$tenant->id, $variant->id, $location->id, $onHandDelta, $reservedDelta, $damagedDelta, auth()->id()]);
    }
}
