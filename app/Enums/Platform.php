<?php

namespace App\Enums;

use App\Imports\LazadaAllProductImporter;
use App\Imports\LazadaOrderImporter;
use App\Imports\LazadaReturnImporter;
use App\Imports\MarketplaceOrderImporter;
use App\Imports\MarketplaceReturnImporter;
use App\Imports\PlatformFileImporter;
use App\Imports\ShopeeAllProductImporter;
use App\Imports\ShopeeOrderImporter;
use App\Imports\ShopeeReturnImporter;
use App\Imports\TiktokAllProductImporter;
use App\Imports\TiktokOrderImporter;
use App\Imports\TiktokReturnImporter;

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
     * The order-export Importer for this marketplace (ROADMAP Phase 4).
     *
     * @return class-string<MarketplaceOrderImporter>|null
     */
    public function orderImporter(): ?string
    {
        return match ($this) {
            self::Shopee => ShopeeOrderImporter::class,
            self::Lazada => LazadaOrderImporter::class,
            self::Tiktok => TiktokOrderImporter::class,
            default => null,
        };
    }

    /**
     * The return-export Importer for this marketplace (ROADMAP Phase 5);
     * null until the platform's importer ships (#43 Lazada, #44 TikTok).
     *
     * @return class-string<MarketplaceReturnImporter>|null
     */
    public function returnImporter(): ?string
    {
        return match ($this) {
            self::Shopee => ShopeeReturnImporter::class,
            self::Lazada => LazadaReturnImporter::class,
            self::Tiktok => TiktokReturnImporter::class,
            default => null,
        };
    }

    /**
     * The existing-listing ("All product") Importer for this marketplace
     * (ROADMAP Phase 9 item D): rebuilds Listing Coverage from Platform
     * reality and populates the (Shop, Platform SKU) → Variant resolution map
     * (CONTEXT.md: Listing Coverage; ADR 0019).
     *
     * @return class-string<PlatformFileImporter>|null
     */
    public function allProductImporter(): ?string
    {
        return match ($this) {
            self::Shopee => ShopeeAllProductImporter::class,
            self::Lazada => LazadaAllProductImporter::class,
            self::Tiktok => TiktokAllProductImporter::class,
            default => null,
        };
    }

    /**
     * Days the Platform gives the buyer to ship an approved return before
     * auto-closing the case — the stale-Return flag's window (ADR 0006).
     * Only TikTok's is documented; null = no flag until the value is
     * confirmed, never a guessed default (ADR 0005).
     */
    public function buyerShipWindowDays(): ?int
    {
        return $this === self::Tiktok ? 5 : null;
    }
}
