<?php

namespace App\Filament\Resources\ExpiringCampaigns;

use App\Enums\PromotionType;
use App\Filament\Resources\ExpiringCampaigns\Pages\ListExpiringCampaigns;
use App\Models\ListingVariant;
use App\Models\Promotion;
use App\Models\Shop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Read-only "Expiring Campaigns" alert list (Issue #77; CONTEXT.md: Promotion).
 * Shows active campaign Promotions whose `end_at` falls within
 * EXPIRY_THRESHOLD_HOURS — the "expiry reminder" surface per CONTEXT.md.
 *
 * Mirrors OverduePayoutResource in shape: read-only, `getEloquentQuery()`
 * pinned to the alert window, gated on `promotion.view` (ADR 0012).
 * BelongsToTenant on Promotion scopes every query by tenant automatically.
 */
class ExpiringCampaignResource extends Resource
{
    /**
     * Hours before `end_at` that a campaign enters the expiry-reminder window.
     * The threshold is intentionally generous (2 days) so the seller has time
     * to decide whether to extend or let the campaign lapse.
     */
    public const EXPIRY_THRESHOLD_HOURS = 48;

    protected static ?string $model = Promotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $modelLabel = 'แคมเปญใกล้หมดอายุ';

    protected static ?string $navigationLabel = 'แคมเปญใกล้หมดอายุ';

    /**
     * Pin the Resource to the expiry-reminder window:
     * – type = campaign (only campaigns have `end_at`; CONTEXT.md: Promotion)
     * – end_at > now   (campaign is still active)
     * – end_at <= now + EXPIRY_THRESHOLD_HOURS  (approaching expiry)
     *
     * BelongsToTenant on Promotion already scopes the query to the current
     * tenant, so no explicit tenant filter is needed.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $now = Date::now();

        return parent::getEloquentQuery()
            ->where('type', PromotionType::Campaign)
            ->where('end_at', '>', $now)
            ->where('end_at', '<=', $now->copy()->addHours(self::EXPIRY_THRESHOLD_HOURS));
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->checkPermissionTo('promotion.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->checkPermissionTo('promotion.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('ชื่อโปรโมชั่น')
                    ->searchable(),
                TextColumn::make('shops')
                    ->label('ร้านที่เกี่ยวข้อง')
                    ->state(fn (Promotion $record): string => self::shopNames($record)),
                TextColumn::make('start_at')
                    ->label('เริ่มต้น')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_at')
                    ->label('สิ้นสุด')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('time_remaining')
                    ->label('เวลาเหลือ')
                    ->state(fn (Promotion $record): string => $record->end_at?->diffForHumans() ?? '-'),
            ])
            ->defaultSort('end_at', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpiringCampaigns::route('/'),
        ];
    }

    /**
     * Collect the distinct Shop names touched by this Promotion's lines.
     * A Promotion carries no direct shop_id — its Shop scope is the set of
     * Shops its Promotion Lines touch (CONTEXT.md: Promotion).
     * Two DB lookups are fine for a small alert list.
     */
    private static function shopNames(Promotion $record): string
    {
        $lvIds = $record->lines()->pluck('listing_variant_id')->filter()->all();

        if (empty($lvIds)) {
            return '-';
        }

        $shopIds = ListingVariant::query()
            ->whereIn('id', $lvIds)
            ->distinct()
            ->pluck('shop_id')
            ->filter()
            ->all();

        if (empty($shopIds)) {
            return '-';
        }

        $names = Shop::query()
            ->whereIn('id', $shopIds)
            ->pluck('name');

        return $names->implode(', ') ?: '-';
    }
}
