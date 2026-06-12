<?php

namespace App\Enums;

/**
 * The 9 Stock Movement actions (CONTEXT.md: Stock Movement, ADR 0003/0013).
 */
enum StockAction: string
{
    case Receive = 'RECEIVE';
    case Ship = 'SHIP';
    case Reserve = 'RESERVE';
    case Release = 'RELEASE';
    case Damage = 'DAMAGE';
    case Restore = 'RESTORE';
    case Recount = 'RECOUNT';
    case TransferOut = 'TRANSFER_OUT';
    case TransferIn = 'TRANSFER_IN';
}
