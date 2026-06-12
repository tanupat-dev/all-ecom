<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Every admin-gated action (void / refund / discount …) records who approved
 * it by calling this (ROADMAP Phase 0 audit rule, ADR 0012).
 */
class RecordAuditLog
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(string $action, ?Model $subject = null, array $details = []): AuditLog
    {
        if (auth()->guest()) {
            throw new LogicException('An audit log needs an authenticated approver — never record one anonymously.');
        }

        return AuditLog::query()->create([
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details === [] ? null : $details,
        ]);
    }
}
