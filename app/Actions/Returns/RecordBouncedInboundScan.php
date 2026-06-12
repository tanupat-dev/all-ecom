<?php

namespace App\Actions\Returns;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Stock\ReceiveReturnedGoods;
use App\Enums\OrderStatus;
use App\Enums\ReturnSubStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Inbound Scan on a whole-package ตีกลับ Order (CONTEXT.md: Inbound Scan;
 * ADR 0006 — Return Sub-Status is shared vocabulary at Order level):
 * credits every Order Line back at the fulfilment Location only when the
 * package physically arrives, then locks at รับของกลับแล้ว.
 */
class RecordBouncedInboundScan
{
    public function __construct(
        private readonly ReceiveReturnedGoods $receive,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  list<int>  $damagedOrderLineIds  the condition check
     */
    public function handle(Order $order, array $damagedOrderLineIds = []): Order
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->checkPermissionTo('return.manage')) {
            throw new AuthorizationException('An Inbound Scan requires the return.manage permission.');
        }

        if ($order->status !== OrderStatus::Bounced) {
            throw new LogicException('Only a ตีกลับ Order takes a whole-package Inbound Scan (CONTEXT.md: Return Sub-Status).');
        }

        if ($order->return_sub_status === ReturnSubStatus::Received) {
            throw new LogicException('The package was already scanned รับของกลับแล้ว — stock never double-credits.');
        }

        return DB::transaction(function () use ($order, $damagedOrderLineIds): Order {
            $location = Location::query()->findOrFail($order->shop()->firstOrFail()->location_id);

            foreach ($order->lines()->get() as $line) {
                $this->receive->handle(
                    $line->variant()->firstOrFail(),
                    $location,
                    $line->qty,
                    in_array($line->id, $damagedOrderLineIds, true),
                    $order,
                );
            }

            $order->update(['return_sub_status' => ReturnSubStatus::Received]);

            $this->audit->handle('return.inbound_scan', $order, [
                'platform_order_id' => $order->platform_order_id,
                'damaged_order_line_ids' => $damagedOrderLineIds,
            ]);

            return $order->refresh();
        });
    }
}
