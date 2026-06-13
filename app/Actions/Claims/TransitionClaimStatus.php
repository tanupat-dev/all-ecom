<?php

namespace App\Actions\Claims;

use App\Enums\ClaimStatus;
use App\Models\Claim;
use InvalidArgumentException;

/**
 * Advances a Claim through its two-stage lifecycle (CONTEXT.md: Claim;
 * Issue #81). Enforces the legal transition graph and fails loud on any
 * illegal move. Authorization is delegated to ClaimPolicy@update at the
 * UI layer (Filament, Issue #84) — this Action is a pure state-machine guard,
 * matching the `CreateClaim` sibling's convention of no self-authorization.
 *
 * Legal graph:
 *   eligible          → submitted_initial | abandoned
 *   submitted_initial → approved | rejected | submitted_ticket | abandoned
 *   submitted_ticket  → approved | rejected | abandoned
 *   approved, rejected, abandoned are TERMINAL — no exit.
 */
class TransitionClaimStatus
{
    /**
     * Keyed by the current (from) status value; lists every valid destination.
     * Terminal states carry an empty list so any transition out throws.
     *
     * @var array<string, list<ClaimStatus>>
     */
    private const LEGAL = [
        'eligible' => [
            ClaimStatus::SubmittedInitial,
            ClaimStatus::Abandoned,
        ],
        'submitted_initial' => [
            ClaimStatus::Approved,
            ClaimStatus::Rejected,
            ClaimStatus::SubmittedTicket,
            ClaimStatus::Abandoned,
        ],
        'submitted_ticket' => [
            ClaimStatus::Approved,
            ClaimStatus::Rejected,
            ClaimStatus::Abandoned,
        ],
        'approved' => [],
        'rejected' => [],
        'abandoned' => [],
    ];

    /**
     * Transition the Claim to `$to`, persisting the new status.
     *
     * @throws InvalidArgumentException when the transition is not legal
     *                                  (includes any move out of a terminal state).
     */
    public function handle(Claim $claim, ClaimStatus $to): Claim
    {
        $from = $claim->status;

        if (! in_array($to, self::LEGAL[$from->value], true)) {
            throw new InvalidArgumentException(
                "Illegal Claim transition: {$from->value} → {$to->value}."
            );
        }

        $claim->update(['status' => $to]);

        return $claim;
    }
}
