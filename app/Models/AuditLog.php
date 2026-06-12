<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only record of who approved an admin-gated action (void / refund /
 * discount …) — the audit requirement of ROADMAP Phase 0 / ADR 0012.
 * created_by = the approver. Follows the ledger pattern: never update/delete.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $action
 * @property int|null $created_by
 * @property array<string, mixed>|null $details
 */
class AuditLog extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['action', 'subject_type', 'subject_id', 'details'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit logs are append-only (ledger pattern) — never update.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit logs are append-only (ledger pattern) — never delete.');
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
