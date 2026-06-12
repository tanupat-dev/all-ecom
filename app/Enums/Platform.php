<?php

namespace App\Enums;

use App\Imports\LazadaOrderImporter;
use App\Imports\MarketplaceOrderImporter;
use App\Imports\ShopeeOrderImporter;

/**
 * The concrete sales channels (CONTEXT.md: Platform).
 */
enum Platform: string
{
    case Shopee = 'shopee';
    case Lazada = 'lazada';
    case Tiktok = 'tiktok';
    case Line = 'line';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Pos = 'pos';

    public function type(): PlatformType
    {
        return match ($this) {
            self::Shopee, self::Lazada, self::Tiktok => PlatformType::Marketplace,
            self::Line, self::Instagram, self::Facebook => PlatformType::Social,
            self::Pos => PlatformType::Pos,
        };
    }

    /**
     * The Order Milestone Date this Platform's payout clock anchors on
     * (ADR 0004): Shopee exposes completed_date; TikTok/Lazada expose
     * delivered_date.
     */
    public function payoutAnchor(): string
    {
        return $this === self::Shopee ? 'completed_date' : 'delivered_date';
    }

    /**
     * The order-export Importer for this marketplace (ROADMAP Phase 4);
     * null until the platform's importer ships (#34 TikTok).
     *
     * @return class-string<MarketplaceOrderImporter>|null
     */
    public function orderImporter(): ?string
    {
        return match ($this) {
            self::Shopee => ShopeeOrderImporter::class,
            self::Lazada => LazadaOrderImporter::class,
            default => null,
        };
    }
}
