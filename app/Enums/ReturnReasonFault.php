<?php

namespace App\Enums;

/**
 * The fault bucket assigned to a Return Reason on import — used for Claim
 * auto-flagging (CONTEXT.md: Return Reason, Claim; ADR 0005 fail-loud).
 *
 * buyer_fault — buyer changed mind / no longer needed. No Claim implication.
 * seller_fault — received wrong / damaged / not as described. Triggers Claim
 *                auto-flagging prompt so the seller can verify and file.
 *
 * Null means "unrecognised reason — ระบบไม่รองรับ"; surfaced in the
 * Unclassified Return Reasons list for manual classification.
 */
enum ReturnReasonFault: string
{
    case BuyerFault = 'buyer_fault';
    case SellerFault = 'seller_fault';
}
