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
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 */
class Product extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['name'];

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }
}
