<?php

namespace App\Actions\Orders;

use App\Actions\Stock\AppendStockMovement;
use App\Actions\Stock\ExpandBundleMovements;
use App\Enums\PlatformType;
use App\Enums\StockAction;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;

/**
 * The shared bridge between the Order lifecycle and the Phase-1 ledger
 * (ROADMAP Phase 2): every line moves at the Shop's fulfilment Location
 * with the Order as ref; a Bundle line expands into its components
 * (ADR 0014); the whole event is one transaction.
 */
class MoveOrderStock
{
    public function __construct(
        private readonly AppendStockMovement $append,
        private readonly ExpandBundleMovements $expand,
    ) {}

    /**
     * @param  iterable<OrderLine>|null  $lines  defaults to all of the order's lines
     */
    public function handle(Order $order, StockAction $action, ?iterable $lines = null, ?int $reservedReleasedPerUnit = null): void
    {
        $location = Location::query()->findOrFail($order->shop()->firstOrFail()->location_id);

        DB::transaction(function () use ($order, $action, $lines, $reservedReleasedPerUnit, $location): void {
            foreach ($lines ?? $order->lines()->get() as $line) {
                $variant = $line->variant()->firstOrFail();
                $shipReleased = $action === StockAction::Ship && $reservedReleasedPerUnit !== null
                    ? $reservedReleasedPerUnit * $line->qty
                    : null;

                if ($variant->isBundle()) {
                    $this->expand->handle($variant, $location, $action, $line->qty, ref: $order, reservedReleased: $shipReleased);
                } else {
                    $this->append->handle($variant, $location, $action, $line->qty, ref: $order, reservedReleased: $shipReleased);
                }
            }
        });
    }

    /**
     * What one shipped unit releases from Reserved: reserving channels
     * reserved the full qty first; POS reserved nothing (CONTEXT.md:
     * Reserved Stock).
     */
    public static function reservedPerUnit(Order $order): int
    {
        return $order->platform_type === PlatformType::Pos ? 0 : 1;
    }
}
