<?php

namespace App\Enums;

/**
 * The 4 POS tenders (CONTEXT.md: Payment). All manually cashier-confirmed
 * in MVP — no gateway/slip verification. Only cash gives change and only
 * cash touches the drawer/expected_cash.
 */
enum TenderType: string
{
    case Cash = 'cash';
    case PromptpayQr = 'promptpay_qr';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
}
