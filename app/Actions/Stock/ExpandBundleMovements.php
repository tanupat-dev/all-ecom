<?php

namespace App\Actions\Stock;

use App\Enums\StockAction;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Selling a Bundle never moves "bundle stock" (ADR 0014): at
 * reserve/ship/release the bundle order line expands into component
 * movements — each component × BOM qty × bundle qty — atomically at the
 * fulfilment Location. SHIP stays order-aware per component:
 * reservedReleased is given in bundle units and scales by each
 * component's BOM qty (POS = 0).
 */
class ExpandBundleMovements
{
    public function __construct(
        private readonly AppendStockMovement $append,
    ) {}

    /**
     * @return list<StockMovement> one movement per component
     */
    public function handle(
        Variant $bundle,
        Location $location,
        StockAction $action,
        int $qty,
        ?Model $ref = null,
        ?string $note = null,
        ?int $reservedReleased = null,
    ): array {
        if (! in_array($action, [StockAction::Reserve, StockAction::Ship, StockAction::Release], true)) {
            throw new InvalidArgumentException('A bundle only expands for RESERVE, SHIP, or RELEASE — adjust component stock directly for anything else.');
        }

        $components = $bundle->bundleComponents()->with('component')->get();

        if ($components->isEmpty()) {
            throw new InvalidArgumentException('Not a bundle — append a movement for the variant directly.');
        }

        return DB::transaction(function () use ($components, $location, $action, $qty, $ref, $note, $reservedReleased): array {
            $movements = [];

            foreach ($components as $bom) {
                $movements[] = $this->append->handle(
                    $bom->component()->firstOrFail(),
                    $location,
                    $action,
                    $qty * $bom->qty,
                    ref: $ref,
                    note: $note,
                    reservedReleased: $action === StockAction::Ship && $reservedReleased !== null
                        ? $reservedReleased * $bom->qty
                        : null,
                );
            }

            return $movements;
        });
    }
}
