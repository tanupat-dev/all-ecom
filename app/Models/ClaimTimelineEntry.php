<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable entry on a Claim's Timeline (CONTEXT.md: Claim — Claim
 * Timeline; Issue #83). The system's Phase-0 ledger pattern: never updated or
 * deleted — a correction is a new entry. payout_amount is integer satang
 * (ADR 0015), nullable — only "won ฿X" entries carry money.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $claim_id
 * @property Carbon $occurred_at
 * @property string $action
 * @property string|null $note
 * @property string|null $ticket_no
 * @property Money|null $payout_amount
 */
class ClaimTimelineEntry extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = [
        'claim_id', 'occurred_at', 'action', 'note', 'ticket_no', 'payout_amount',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payout_amount' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Claim Timeline entries are an append-only ledger (Issue #83) — never update; append a correction.');
        });

        static::deleting(function (): never {
            throw new LogicException('Claim Timeline entries are an append-only ledger (Issue #83) — never delete; append a correction.');
        });
    }

    /**
     * @return BelongsTo<Claim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
