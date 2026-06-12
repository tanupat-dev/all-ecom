<?php

namespace App\Actions\Returns;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Stock\AppendStockMovement;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Enums\StockAction;
use App\Models\Location;
use App\Models\OrderReturn;
use App\Models\ReturnLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Inbound Scan on a Return (CONTEXT.md: Inbound Scan, Stock Return):
 * stock comes back ONLY here — never because the courier or platform
 * claims arrival. Credits a RECEIVE per Return Line × qty at the Shop's
 * fulfilment Location (damaged units route on to the Damaged pool, the
 * POS-return pattern), then locks the Return at the terminal
 * รับของกลับแล้ว so it can never double-credit.
 */
class RecordInboundScan
{
    public function __construct(
        private readonly AppendStockMovement $append,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  list<int>  $damagedReturnLineIds  the Cashier's condition check
     */
    public function handle(OrderReturn $return, array $damagedReturnLineIds = []): OrderReturn
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->checkPermissionTo('return.manage')) {
            throw new AuthorizationException('An Inbound Scan requires the return.manage permission.');
        }

        if ($return->return_type === ReturnType::RefundOnly) {
            throw new LogicException('A refund_only Return never touches stock — there is nothing to scan (CONTEXT.md: Return).');
        }

        if ($return->sub_status->isTerminal()) {
            throw new LogicException("Return [{$return->platform_return_id}] is terminal [{$return->sub_status->value}] — stock never double-credits.");
        }

        return DB::transaction(function () use ($return, $damagedReturnLineIds): OrderReturn {
            $location = Location::query()->findOrFail($return->shop()->firstOrFail()->location_id);

            foreach ($return->lines()->with('orderLine')->get() as $line) {
                $this->receive(
                    $return,
                    $line,
                    $location,
                    in_array($line->id, $damagedReturnLineIds, true),
                );
            }

            $return->update(['sub_status' => ReturnSubStatus::Received]);

            $this->audit->handle('return.inbound_scan', $return, [
                'platform_return_id' => $return->platform_return_id,
                'damaged_return_line_ids' => $damagedReturnLineIds,
            ]);

            return $return->refresh()->load('lines');
        });
    }

    private function receive(OrderReturn $return, ReturnLine $line, Location $location, bool $damaged): void
    {
        $variant = $line->orderLine()->firstOrFail()->variant()->firstOrFail();

        if ($variant->isBundle()) {
            if ($damaged) {
                throw new InvalidArgumentException('A damaged Bundle return is out of MVP scope — adjust the damaged components via Stock Adjustment.');
            }

            // Components come back, never "bundle stock" (ADR 0014).
            foreach ($variant->bundleComponents()->with('component')->get() as $bom) {
                $this->append->handle($bom->component()->firstOrFail(), $location, StockAction::Receive, $line->qty * $bom->qty, ref: $return);
            }

            return;
        }

        $this->append->handle($variant, $location, StockAction::Receive, $line->qty, ref: $return);

        if ($damaged) {
            $this->append->handle($variant, $location, StockAction::Damage, $line->qty, ref: $return, note: 'ตรวจสภาพตอนสแกนรับของ — ชำรุด');
        }
    }
}
