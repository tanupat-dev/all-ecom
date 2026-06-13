<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proof item in a Claim's Evidence Checklist (CONTEXT.md: Claim, Evidence
 * Checklist; Issue #82). The four default items are seeded automatically on
 * Claim creation. Sellers may extend the list per Claim.
 *
 * `checked` is a mutable bool — this is a working checklist, not an
 * append-only ledger. `is_default` distinguishes platform-seeded items from
 * seller-added ones.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $claim_id
 * @property string $label
 * @property bool $checked
 * @property bool $is_default
 */
class ClaimEvidenceItem extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = [
        'claim_id', 'label', 'checked', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'checked' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Claim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
