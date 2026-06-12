<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fixed-till checkout point inside a pos Shop (CONTEXT.md: Register) —
 * the thing a Shift opens on; at most one open Shift per Register.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property string $name
 * @property bool $active
 */
class Register extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['shop_id', 'name', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
