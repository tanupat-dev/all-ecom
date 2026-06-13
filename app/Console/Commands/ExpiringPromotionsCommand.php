<?php

namespace App\Console\Commands;

use App\Enums\PromotionType;
use App\Filament\Resources\ExpiringCampaigns\ExpiringCampaignResource;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Surfaces the count of campaign Promotions approaching expiry per tenant
 * (Issue #77; CONTEXT.md: "System emits expiry reminders when an active
 * campaign approaches end_at"). For MVP the reminder is a log entry and the
 * Filament ExpiringCampaignResource list — a notification channel is deferred.
 *
 * Idempotent: re-running reads the same window and emits the same counts;
 * no data is written, so there is nothing to duplicate or side-effect.
 * Tenant-looping pattern mirrors RebuildDailyPnl — the CLI has no logged-in
 * user, so the TenantContext must be set explicitly per tenant before each RLS
 * query (ADR 0016/0018).
 */
class ExpiringPromotionsCommand extends Command
{
    protected $signature = 'promotions:expiring';

    protected $description = 'Log the count of campaigns expiring within '.ExpiringCampaignResource::EXPIRY_THRESHOLD_HOURS.'h per tenant (idempotent; Issue #77)';

    public function handle(TenantContext $context): int
    {
        $previous = $context->current();

        try {
            $tenants = Tenant::query()->get();

            foreach ($tenants as $tenant) {
                $context->set($tenant);

                $now = Date::now();

                /** @var int $count */
                $count = Promotion::query()
                    ->where('type', PromotionType::Campaign)
                    ->where('end_at', '>', $now)
                    ->where('end_at', '<=', $now->copy()->addHours(ExpiringCampaignResource::EXPIRY_THRESHOLD_HOURS))
                    ->count();

                if ($count > 0) {
                    $this->line(
                        "Tenant {$tenant->id} ({$tenant->name}): {$count} campaign(s) expiring within "
                        .ExpiringCampaignResource::EXPIRY_THRESHOLD_HOURS.'h.'
                    );

                    Log::info('promotions:expiring', [
                        'tenant_id' => $tenant->id,
                        'expiring_count' => $count,
                        'threshold_hours' => ExpiringCampaignResource::EXPIRY_THRESHOLD_HOURS,
                    ]);
                }
            }
        } finally {
            $previous !== null ? $context->set($previous) : $context->forget();
        }

        return self::SUCCESS;
    }
}
