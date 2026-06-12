<?php

namespace App\Imports;

use App\Actions\Stock\AppendStockMovement;
use App\Enums\StockAction;
use App\Models\ImportJob;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Variant;
use LogicException;

/**
 * Excel Stock Adjustment (ROADMAP Phase 1 / CONTEXT.md: Stock Adjustment):
 * columns master_sku · location · action (receive|recount|damage|restore) ·
 * qty. Every mapping is fail-loud (ADR 0005) — unknown SKU/Location/action
 * or a bad qty holds the row, never defaults. Rows append through the
 * ledger Action; each movement refs (ImportJob, row N) so a queue retry
 * never double-applies.
 */
class StockAdjustmentImporter implements Importer, ImportJobAware
{
    private const ACTIONS = [
        'receive' => StockAction::Receive,
        'recount' => StockAction::Recount,
        'damage' => StockAction::Damage,
        'restore' => StockAction::Restore,
    ];

    private ?ImportJob $importJob = null;

    public function __construct(
        private readonly AppendStockMovement $append,
    ) {}

    public function setImportJob(ImportJob $importJob): void
    {
        $this->importJob = $importJob;
    }

    public function mapRow(array $row, int $rowNumber): array
    {
        $sku = is_scalar($row['master_sku'] ?? null) ? trim((string) $row['master_sku']) : '';
        $variant = Variant::query()->where('master_sku', $sku)->first()
            ?? throw new RowImportException("Unknown Master SKU [{$sku}]");

        if ($variant->isBundle()) {
            throw new RowImportException("[{$sku}] is a Bundle — it is virtual and holds no stock of its own; adjust its components (ADR 0014)");
        }

        $locationName = is_scalar($row['location'] ?? null) ? trim((string) $row['location']) : '';
        $location = Location::query()->where('name', $locationName)->first()
            ?? throw new RowImportException("Unknown Location [{$locationName}]");

        $actionValue = is_scalar($row['action'] ?? null) ? strtolower(trim((string) $row['action'])) : '';
        $action = self::ACTIONS[$actionValue]
            ?? throw new RowImportException("Unmapped action [{$actionValue}] — expected receive|recount|damage|restore");

        $rawQty = $row['qty'] ?? null;

        if (! is_numeric($rawQty) || (string) (int) $rawQty !== trim((string) $rawQty)) {
            throw new RowImportException('qty must be an integer, got ['.(is_scalar($rawQty) ? $rawQty : gettype($rawQty)).']');
        }

        $qty = (int) $rawQty;

        if ($action === StockAction::Recount ? $qty === 0 : $qty <= 0) {
            throw new RowImportException("qty [{$qty}] is invalid for action [{$actionValue}]");
        }

        return [
            'row' => $rowNumber,
            'variant_id' => $variant->id,
            'location_id' => $location->id,
            'action' => $action,
            'qty' => $qty,
        ];
    }

    /**
     * @param  list<array{row: int, variant_id: int, location_id: int, action: StockAction, qty: int}>  $chunk
     */
    public function upsertChunk(array $chunk): void
    {
        $importJob = $this->importJob
            ?? throw new LogicException('StockAdjustmentImporter needs its ImportJob injected (ImportJobAware).');

        foreach ($chunk as $row) {
            $note = "import row {$row['row']}";

            $alreadyApplied = StockMovement::query()
                ->where('ref_type', $importJob->getMorphClass())
                ->where('ref_id', $importJob->id)
                ->where('note', $note)
                ->exists();

            if ($alreadyApplied) {
                continue;
            }

            $this->append->handle(
                Variant::query()->findOrFail($row['variant_id']),
                Location::query()->findOrFail($row['location_id']),
                $row['action'],
                $row['qty'],
                ref: $importJob,
                note: $note,
            );
        }
    }
}
