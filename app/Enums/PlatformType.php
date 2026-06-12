<?php

namespace App\Enums;

/**
 * The three sales-channel types (CONTEXT.md: Platform) — they share one
 * Order/Stock model and differ in how Orders enter the system.
 */
enum PlatformType: string
{
    case Marketplace = 'marketplace';
    case Social = 'social';
    case Pos = 'pos';
}
