<?php

namespace App\Console\Commands;

use App\Actions\Accounting\RecomputeDailyPnl;
use App\Models\Shop;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Idempotent rebuild of the Daily P&L rollups over a date range (Issue #71) —
 * the recovery path when the queue dropped a job or the table was wiped.
 * Recomputes every (shop, date) in [from, to] from raw via RecomputeDailyPnl,
 * which is idempotent (upsert), so re-running never doubles a rollup.
 *
 * Tenant-scoped explicitly: the command loops Tenants and sets the tenant
 * context before each tenant's reads (a CLI has no logged-in user, and every
 * read is RLS-protected). `--tenant=ID` limits it to one tenant. The `from`
 * and `to` dates are Asia/Bangkok calendar dates (the rollup bucket).
 */
class RebuildDailyPnl extends Command
{
    protected $signature = 'pnl:rebuild {from : start date (YYYY-MM-DD, inclusive)} {to : end date (YYYY-MM-DD, inclusive)} {--tenant= : limit to one tenant id}';

    protected $description = 'Rebuild the Daily P&L rollups over a date range (idempotent, from raw)';

    public function handle(RecomputeDailyPnl $recompute, TenantContext $context): int
    {
        $from = CarbonImmutable::parse($this->argument('from'))->startOfDay();
        $to = CarbonImmutable::parse($this->argument('to'))->startOfDay();

        if ($to->lessThan($from)) {
            $this->error('The `to` date must not be before the `from` date.');

            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $previous = $context->current();

        try {
            foreach ($tenants as $tenant) {
                $context->set($tenant);

                $shops = Shop::query()->get();

                for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                    foreach ($shops as $shop) {
                        $recompute->handle($shop->id, $date);
                    }
                }
            }
        } finally {
            $previous !== null ? $context->set($previous) : $context->forget();
        }

        $this->info("Rebuilt Daily P&L rollups for {$tenants->count()} tenant(s) over {$from->toDateString()} → {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
