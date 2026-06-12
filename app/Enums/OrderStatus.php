<?php

namespace App\Enums;

/**
 * The canonical 8 Order Statuses in 4 phases (CONTEXT.md: Order Status).
 * Stored as the canonical Thai values per CONVENTIONS naming. Platform
 * native statuses are MAPPED into this set on import, fail-loud (ADR 0005).
 */
enum OrderStatus: string
{
    // Pre-Pack (no Tracking Number yet)
    case PendingPayment = 'รอชำระ';
    case AwaitingPack = 'รอแพ็ค';

    // Post-Pack (Tracking Number exists)
    case Packed = 'แพ็คแล้ว';
    case InTransit = 'กำลังขนส่ง';
    case Delivered = 'ถึงปลายทาง';

    // Closed
    case Completed = 'สำเร็จ';
    case Cancelled = 'ยกเลิก';
    case Bounced = 'ตีกลับ';

    /**
     * The lifecycle subset a channel actually uses: a pos Order has no
     * Tracking and closes at the counter (CONTEXT.md: Order Status).
     *
     * @return list<self>
     */
    public static function allowedFor(PlatformType $type): array
    {
        return match ($type) {
            PlatformType::Pos => [self::PendingPayment, self::Completed, self::Cancelled],
            PlatformType::Marketplace, PlatformType::Social => self::cases(),
        };
    }
}
