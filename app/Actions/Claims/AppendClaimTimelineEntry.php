<?php

namespace App\Actions\Claims;

use App\Models\Claim;
use App\Models\ClaimTimelineEntry;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Appends one entry to a Claim's Timeline (CONTEXT.md: Claim — Claim Timeline;
 * Issue #83). The Timeline is an append-only ledger — this Action only ever
 * creates; corrections are new entries, never edits. occurred_at defaults to
 * now() when the caller does not supply the date the action actually happened.
 * payout_amount is integer satang via Money (ADR 0015) — never a float.
 *
 * Gating is the UI/Policy's job (ClaimTimelineEntryPolicy, claim.manage),
 * mirroring CreateClaim — the Action stays a pure side-effect on the ledger.
 */
class AppendClaimTimelineEntry
{
    public function handle(
        Claim $claim,
        string $action,
        ?string $note = null,
        ?string $ticketNo = null,
        ?Money $payoutAmount = null,
        ?Carbon $occurredAt = null,
    ): ClaimTimelineEntry {
        return ClaimTimelineEntry::query()->create([
            'claim_id' => $claim->id,
            'occurred_at' => $occurredAt ?? Carbon::now(),
            'action' => $action,
            'note' => $note,
            'ticket_no' => $ticketNo,
            'payout_amount' => $payoutAmount,
        ]);
    }
}
