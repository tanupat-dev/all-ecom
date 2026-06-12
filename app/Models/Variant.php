<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sellable unit (CONTEXT.md: Variant): carries Master SKU, List Price
 * (identical on every platform), and barcode. Stock and Buffer are tracked
 * per (Variant, Location) — ADR 0013. A Product with no real options has
 * exactly one default Variant (name = null).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_id
 * @property string $master_sku
 * @property string|null $name
 * @property string|null $barcode
 * @property Money|null $list_price
 */
class Variant extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['master_sku', 'name', 'barcode', 'list_price'];

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
}
