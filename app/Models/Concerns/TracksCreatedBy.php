<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fills created_by with the authenticated User on create (CONVENTIONS DB
 * rules — every entity records its creator).
 */
trait TracksCreatedBy
{
    public static function bootTracksCreatedBy(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('created_by') === null) {
                $model->setAttribute('created_by', auth()->id());
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
