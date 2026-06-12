<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A physical place that holds stock (CONTEXT.md: Location, ADR 0013).
 * Stock is per (Variant, Location); every Tenant has exactly one default.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property bool $is_default
 */
class Location extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['name', 'is_default'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Location $location): void {
            if ($location->is_default) {
                throw new LogicException('The default Location cannot be deleted — every Tenant keeps one (ADR 0013).');
            }
        });
    }
}
