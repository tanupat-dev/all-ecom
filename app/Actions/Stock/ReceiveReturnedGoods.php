<?php

namespace App\Actions\Stock;

use App\Enums\StockAction;
use App\Models\Location;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The one way physical goods come back into stock (CONTEXT.md: Stock
 * Return) — shared by the POS return, the Return Inbound Scan, and the
 * ตีกลับ Order scan: RECEIVE at the Location (a Bundle credits its
 * components, ADR 0014); the condition check routes damaged units on to
 * the Damaged pool via a RECEIVE + DAMAGE pair.
 */
class ReceiveReturnedGoods
{
    public function __construct(
        private readonly AppendStockMovement $append,
    ) {}

    public function handle(Variant $variant, Location $location, int $qty, bool $damaged, Model $ref): void
    {
        if ($variant->isBundle()) {
            if ($damaged) {
                throw new InvalidArgumentException('A damaged Bundle return is out of MVP scope — adjust the damaged components via Stock Adjustment.');
            }

            // Components come back, never "bundle stock" (ADR 0014).
            foreach ($variant->bundleComponents()->with('component')->get() as $bom) {
                $this->append->handle($bom->component()->firstOrFail(), $location, StockAction::Receive, $qty * $bom->qty, ref: $ref);
            }

            return;
        }

        $this->append->handle($variant, $location, StockAction::Receive, $qty, ref: $ref);

        if ($damaged) {
            $this->append->handle($variant, $location, StockAction::Damage, $qty, ref: $ref, note: 'ตรวจสภาพตอนสแกนรับของ — ชำรุด');
        }
    }
}
