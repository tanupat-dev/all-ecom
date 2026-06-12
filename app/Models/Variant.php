<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The sellable unit (CONTEXT.md: Variant): carries Master SKU, List Price
 * (identical on every platform), and barcode. Stock and Buffer are tracked
 * per (Variant, Location) — ADR 0013. A Product with no real options has
 * exactly one default Variant (name = null).
 *
 * Package dimensions (ADR 0019, Issue #46): integers in grams / millimetres
 * — mirrors the no-float rule (ADR 0015). Platform-unit conversion (kg, cm)
 * happens at Channel Upload Template fill time, NOT here.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_id
 * @property string $master_sku
 * @property string|null $name
 * @property string|null $barcode
 * @property int|null $package_weight_g
 * @property int|null $package_width_mm
 * @property int|null $package_length_mm
 * @property int|null $package_height_mm
 * @property Money|null $list_price
 */
class Variant extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['master_sku', 'name', 'barcode', 'list_price', 'package_weight_g', 'package_width_mm', 'package_length_mm', 'package_height_mm'];

    protected function casts(): array
    {
        return [
            'list_price' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<CostPrice, $this>
     */
    public function costPrices(): HasMany
    {
        return $this->hasMany(CostPrice::class);
    }

    /**
     * All ListingVariant rows for this Variant (one per Shop × Platform SKU
     * mapping). Used by Listing Coverage to build the Variant × Shop matrix
     * without N+1 queries (CONTEXT.md: Listing Coverage; ADR 0019).
     *
     * @return HasMany<ListingVariant, $this>
     */
    public function listingVariants(): HasMany
    {
        return $this->hasMany(ListingVariant::class);
    }

    /**
     * @return HasMany<BundleComponent, $this>
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_variant_id');
    }

    public function isBundle(): bool
    {
        return $this->bundleComponents()->exists();
    }

    /**
     * Available at a Location. A normal variant reads its denormalized
     * balance; a Bundle has no stored Available — it derives
     * min(floor(component available / qty)) (ADR 0014). Either may go
     * negative (oversell).
     */
    public function availableAt(Location $location): int
    {
        $components = $this->bundleComponents()->with('component')->get();

        if ($components->isEmpty()) {
            $balance = StockBalance::query()
                ->where('variant_id', $this->id)
                ->where('location_id', $location->id)
                ->first();

            return $balance->available ?? 0;
        }

        return (int) $components
            ->map(fn (BundleComponent $bom): int => (int) floor(
                $bom->component()->firstOrFail()->availableAt($location) / $bom->qty,
            ))
            ->min();
    }

    /**
     * The cost in force at $at — the latest history row with
     * valid_from ≤ $at (CONVENTIONS rule 9: profit uses the cost at the
     * sale date). A Bundle's cost = Σ component cost at that date × qty
     * (ADR 0014); null if any component has no cost yet — never guess.
     */
    public function costAt(DateTimeInterface $at): ?Money
    {
        $components = $this->bundleComponents()->with('component')->get();

        if ($components->isNotEmpty()) {
            $total = Money::fromSatang(0);

            foreach ($components as $bom) {
                $componentCost = $bom->component()->firstOrFail()->costAt($at);

                if ($componentCost === null) {
                    return null;
                }

                $total = $total->add($componentCost->multiply($bom->qty));
            }

            return $total;
        }

        return $this->costPrices()
            ->where('valid_from', '<=', $at)
            ->orderByDesc('valid_from')
            ->first()
            ?->cost;
    }

    public function currentCost(): ?Money
    {
        return $this->costAt(now());
    }
}
