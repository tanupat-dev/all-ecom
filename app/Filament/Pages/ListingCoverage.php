<?php

namespace App\Filament\Pages;

use App\Enums\PlatformType;
use App\Models\Shop;
use App\Models\Variant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Listing Coverage matrix — Variant × marketplace Shop (CONTEXT.md:
 * Listing Coverage; ADR 0019). Read-only; each cell shows the Listing
 * Status badge (ร่าง/ลงแล้ว) or a gap marker (—). A gap-list filter
 * ("ไม่ได้ลงในร้านนี้") scopes the view to only Variants missing on a
 * selected Shop.
 *
 * Gated on `listing.view` (RBAC — ADR 0012). Shops are built at runtime
 * from the tenant's marketplace Shops; POS shops are excluded because Listings
 * do not exist for POS (ADR 0010).
 *
 * N+1 avoidance: the Variant query eager-loads `product` and
 * `listingVariants`, so each shop column accesses an in-memory collection —
 * the table page costs two queries regardless of the number of shops or
 * variants.
 */
class ListingCoverage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $title = 'ความครบของการลงสินค้า (Listing Coverage)';

    protected static ?string $navigationLabel = 'Listing Coverage';

    protected string $view = 'filament.pages.listing-coverage';

    public static function canAccess(): bool
    {
        return auth()->user()?->checkPermissionTo('listing.view') ?? false;
    }

    public function table(Table $table): Table
    {
        /** @var Collection<int, Shop> $shops */
        $shops = Shop::query()
            ->where('platform_type', '!=', PlatformType::Pos->value)
            ->orderBy('name')
            ->get();

        /** @var list<Column> $columns */
        $columns = [
            TextColumn::make('master_sku')
                ->label('Master SKU')
                ->searchable()
                ->sortable(),
            TextColumn::make('product.name')
                ->label('สินค้า')
                ->searchable(),
        ];

        foreach ($shops as $shop) {
            $shopId = $shop->id;

            $columns[] = TextColumn::make('coverage_shop_'.$shopId)
                ->label($shop->name)
                ->badge()
                ->state(function (Variant $record) use ($shopId): string {
                    $lv = $record->listingVariants
                        ->where('shop_id', $shopId)
                        ->first();

                    return $lv !== null ? $lv->listing_status->getLabel() : '—';
                })
                ->color(function (Variant $record) use ($shopId): string {
                    $lv = $record->listingVariants
                        ->where('shop_id', $shopId)
                        ->first();

                    return $lv !== null ? $lv->listing_status->getColor() : 'gray';
                });
        }

        return $table
            ->query(fn (): Builder => Variant::query()->with(['product', 'listingVariants']))
            ->columns($columns)
            ->filters([
                SelectFilter::make('gap_shop')
                    ->label('ไม่ได้ลงในร้านนี้')
                    ->options(fn (): array => Shop::query()
                        ->where('platform_type', '!=', PlatformType::Pos->value)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->modifyQueryUsing(function (Builder $query, array $data): void {
                        $shopId = $data['value'] ?? null;

                        // The value arrives as int from Livewire wire data or as a string
                        // from URL query-string. Accept either and let Eloquent coerce.
                        if ((is_string($shopId) || is_int($shopId)) && filled($shopId)) {
                            $query->whereDoesntHave(
                                'listingVariants',
                                fn (Builder $q) => $q->where('shop_id', $shopId),
                            );
                        }
                    }),
            ]);
    }
}
