<?php

namespace App\Console\Commands;

use App\Actions\Promotions\RefreshDealPriceCache;
use App\Models\ListingVariant;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Recomputes the Deal Price cache (ListingVariant.deal_price, ADR 0021) for
 * every Listing-Variant. The observer keeps the cache exact on every write, but
 * a campaign opening or closing is a TIME boundary with no write — so a cache
 * set while a campaign was in window goes stale the instant the window closes.
 * Running this hourly bounds that staleness to ≤1h; the export's exact path is
 * always ResolveEffectivePrice (this only keeps the hot read fresh). Idempotent:
 * it rewrites each cache to the same value the Action would resolve, so
 * re-running never drifts.
 *
 * Tenant-scoped explicitly (a CLI has no logged-in user, every read is
 * RLS-protected): loops Tenants and sets the context before each tenant's reads
 * — mirrors pnl:rebuild. `--tenant=ID` limits it to one tenant.
 */
class RefreshDealPriceCacheCommand extends Command
{
    protected $signature = 'promotions:refresh-cache {--tenant= : limit to one tenant id}';

    protected $description = 'Recompute the Deal Price cache for every Listing-Variant (time-boundary refresh; idempotent)';

    public function handle(RefreshDealPriceCache $refresh, TenantContext $context): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $previous = $context->current();
        $refreshed = 0;

        try {
            foreach ($tenants as $tenant) {
                $context->set($tenant);

                ListingVariant::query()->each(function (ListingVariant $listingVariant) use ($refresh, &$refreshed): void {
                    $refresh->handle($listingVariant);
                    $refreshed++;
                });
            }
        } finally {
            $previous !== null ? $context->set($previous) : $context->forget();
        }

        $this->info("Refreshed the Deal Price cache for {$refreshed} Listing-Variant(s) across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
