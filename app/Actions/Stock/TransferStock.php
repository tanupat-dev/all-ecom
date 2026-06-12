<?php

namespace App\Actions\Stock;

use App\Enums\StockAction;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inter-Location Transfer (ADR 0013 / CONTEXT.md Location): one atomic
 * TRANSFER_OUT + TRANSFER_IN pair — stock moves, nothing is created or
 * destroyed. The IN row references its OUT row so the pair stays linked.
 */
class TransferStock
{
    public function __construct(
        private readonly AppendStockMovement $append,
    ) {}

    /**
     * @return array{StockMovement, StockMovement} [$out, $in]
     */
    public function handle(Variant $variant, Location $source, Location $destination, int $qty, ?string $note = null): array
    {
        if ($source->is($destination)) {
            throw new InvalidArgumentException('A Transfer needs two different Locations.');
        }

        return DB::transaction(function () use ($variant, $source, $destination, $qty, $note): array {
            $out = $this->append->handle($variant, $source, StockAction::TransferOut, $qty, note: $note);
            $in = $this->append->handle($variant, $destination, StockAction::TransferIn, $qty, ref: $out, note: $note);

            return [$out, $in];
        });
    }
}
