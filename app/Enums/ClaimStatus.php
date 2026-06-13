<?php

namespace App\Enums;

/**
 * The Claim lifecycle (CONTEXT.md: Claim) — a two-stage flow: an initial
 * Claim filing (submitted_initial), then a stage-2 support ticket
 * (submitted_ticket) if the first round is rejected. Mirrors the
 * ReturnSubStatus terminal-marker pattern (ADR 0006).
 */
enum ClaimStatus: string
{
    case Eligible = 'eligible';
    case SubmittedInitial = 'submitted_initial';
    case SubmittedTicket = 'submitted_ticket';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Abandoned = 'abandoned';

    /**
     * Terminal states close the Claim: approved (won), rejected (lost or not
     * escalated), abandoned (manually closed) — none reopen (CONTEXT.md: Claim).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected, self::Abandoned => true,
            default => false,
        };
    }
}
