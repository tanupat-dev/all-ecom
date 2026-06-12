<?php

namespace App\Enums;

/**
 * The goods-coming-back journey, gated by Inbound Scan (CONTEXT.md:
 * Return Sub-Status) — shared vocabulary on Return entities and on a
 * whole-Order ตีกลับ (ADR 0006).
 */
enum ReturnSubStatus: string
{
    case AwaitingBuyerShipment = 'รอผู้ซื้อส่งคืน';
    case InTransitBack = 'ขนส่งกำลังนำส่งกลับ';
    case CourierClaimsDelivered = 'ขนส่งแจ้งถึงร้านแล้ว';
    case Received = 'รับของกลับแล้ว';
    case Closed = 'ยกเลิกการคืน';

    /**
     * Terminal states lock against revert: Received already credited
     * stock; Closed is the Platform's final word (ADR 0006).
     */
    public function isTerminal(): bool
    {
        return $this === self::Received || $this === self::Closed;
    }
}
