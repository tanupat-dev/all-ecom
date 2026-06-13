<?php

namespace App\Actions\Returns;

use App\Enums\Platform;
use App\Enums\ReturnReasonFault;

/**
 * Classifies a Platform's raw return-reason text into a fault bucket
 * (CONTEXT.md: Return Reason; ADR 0005 fail-loud).
 *
 * Returns ReturnReasonFault::BuyerFault / SellerFault for known reasons,
 * or null for any reason text not in the platform's documented list —
 * never silently defaults to either bucket.
 *
 * TikTok ships pre-shipment cancellation texts in the same export file as
 * genuine returns. These are NOT returns (no Claim logic) and are distinct
 * from "unknown" reasons: handle() returns null for both, but
 * isPreShipmentCancellation() lets callers distinguish the two cases.
 */
class ClassifyReturnReason
{
    /**
     * Per-platform buyer-fault reason texts (CONTEXT.md: Return Reason).
     *
     * @var array<string, list<string>>
     */
    private const BUYER_FAULT = [
        Platform::Shopee->value => [
            'ฉันต้องการคืนสินค้าในสภาพสมบูรณ์',
        ],
        Platform::Tiktok->value => [
            'ไม่ต้องการอีกต่อไป',
            'No longer needed',
        ],
        Platform::Lazada->value => [
            'Change of mind',
        ],
    ];

    /**
     * Per-platform seller-fault reason texts (CONTEXT.md: Return Reason).
     *
     * @var array<string, list<string>>
     */
    private const SELLER_FAULT = [
        Platform::Shopee->value => [
            'สินค้าหมดอายุ',
            'ทำงานไม่สมบูรณ์',
            'แตกหัก',
            'รอยขีดข่วน/บุบ',
            'ความเสียหายอื่นๆ',
            'ได้รับสินค้าผิด',
            'สินค้าแตกต่างจากที่สั่ง',
            'ไม่ได้รับพัสดุ',
            'สินค้าไม่ครบ/ชิ้นส่วนไม่ครบ',
            'กล่องเปล่า',
        ],
        Platform::Tiktok->value => [
            'สินค้าไม่ตรงกับคำอธิบาย',
            'สินค้าไม่ถูกต้อง/ส่งสินค้าผิด',
            'สินค้ามีตำหนิหรือใช้งานไม่ได้',
            'ได้รับพัสดุแต่มีสินค้าขาดหาย',
            'พัสดุหรือสินค้าเสียหาย',
            'ไม่ได้รับพัสดุ',
            'สงสัยว่าเป็นของปลอม',
            'สินค้าหมดอายุ',
            'บรรจุภัณฑ์เสียหาย',
        ],
        Platform::Lazada->value => [
            'Expired items',
            'Missing items in the parcel delivered',
            'Item physically damaged upon opening parcel',
            'Outer Packaging of the item is damage',
            'Item size is not advertised',
            'Missing accessories or freebies',
            'Counterfeit items',
            'Item is defective or not working as intended',
            'Wrong items delivered',
            "Item/ quality doesn't match description or pictures",
        ],
    ];

    /**
     * TikTok pre-shipment cancellation reasons that appear in the return
     * export but are NOT genuine returns — no Claim logic applies
     * (CONTEXT.md: Return Reason). Classified as skip (stored as null
     * reason_fault), distinct from "unknown reason" via isPreShipmentCancellation().
     *
     * @var list<string>
     */
    private const TIKTOK_PRESHIPMENT = [
        'สินค้ามาถึงไม่ตรงเวลา',
        'ต้องการเปลี่ยนวิธีการชำระเงิน',
        'จำเป็นต้องเปลี่ยนที่อยู่จัดส่ง',
        'มีราคาที่ดีกว่า',
    ];

    /**
     * Classify a raw return-reason text into a fault bucket.
     *
     * Returns null for:
     * - a null or blank reason (no information)
     * - an unknown reason not in the platform's documented list (ADR 0005)
     * - a TikTok pre-shipment cancellation reason (not a return)
     *
     * Callers that need to distinguish "skip" from "unknown" should
     * additionally call isPreShipmentCancellation().
     */
    public function handle(Platform $platform, ?string $reason): ?ReturnReasonFault
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        $key = $platform->value;

        if (in_array($reason, self::BUYER_FAULT[$key] ?? [], true)) {
            return ReturnReasonFault::BuyerFault;
        }

        if (in_array($reason, self::SELLER_FAULT[$key] ?? [], true)) {
            return ReturnReasonFault::SellerFault;
        }

        // Pre-shipment TikTok cancellations and unknown reasons both return null.
        return null;
    }

    /**
     * Returns true when the reason is a TikTok pre-shipment cancellation text
     * that appeared in the return export but is NOT a genuine return.
     * Always false for non-TikTok platforms.
     *
     * Use alongside handle() to distinguish "skip — not a return" from
     * "unknown reason — ระบบไม่รองรับ" (both yield null from handle()).
     */
    public function isPreShipmentCancellation(Platform $platform, ?string $reason): bool
    {
        if ($platform !== Platform::Tiktok || $reason === null) {
            return false;
        }

        return in_array($reason, self::TIKTOK_PRESHIPMENT, true);
    }
}
