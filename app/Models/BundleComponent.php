<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One BOM row of a Bundle (ADR 0014): the bundle Variant needs `qty` of
 * the component Variant per unit sold.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $bundle_variant_id
 * @property int $component_variant_id
 * @property int $qty
 */
class BundleComponent extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['bundle_variant_id', 'component_variant_id', 'qty'];

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'bundle_variant_id');
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }
}
