<?php

namespace App\Actions\Pos;

use App\Models\Shop;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The next receipt_no, sequential per pos Shop (CONTEXT.md: Receipt) —
 * race-safe via an atomic counter upsert; call inside the checkout
 * transaction so an aborted sale never burns a number… it may leave a
 * gap only if the transaction commits the counter without the order,
 * which the shared transaction prevents.
 */
class NextReceiptNumber
{
    public function handle(Shop $shop): int
    {
        $tenant = app(TenantContext::class)->current()
            ?? throw new LogicException('A receipt number needs a tenant context.');

        $number = DB::scalar(<<<'SQL'
            insert into receipt_counters (tenant_id, shop_id, last_number, created_by, created_at, updated_at)
            values (?, ?, 1, ?, now(), now())
            on conflict (tenant_id, shop_id) do update
                set last_number = receipt_counters.last_number + 1, updated_at = now()
            returning last_number
            SQL, [$tenant->id, $shop->id, auth()->id()]);

        if (! is_int($number) || $number < 1) {
            throw new LogicException('Failed to advance the receipt counter.');
        }

        return $number;
    }
}
