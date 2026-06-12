<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable item in the master catalog (CONTEXT.md: Product). The sellable
 * unit is the Variant — a Product always has at least one.
 *
 * Channel-agnostic listing fields (ADR 0019, Issue #46) — authored once,
 * shared across every Platform, feed the Channel Upload Template fill.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $english_name
 * @property string|null $description
 * @property string|null $brand
 */
class Product extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['name', 'english_name', 'description', 'brand'];

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }
}
