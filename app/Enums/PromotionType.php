<?php

namespace App\Enums;

/**
 * The two kinds of Promotion under one model (CONTEXT.md: Promotion; ADR 0021):
 * a `base` markdown (no time window, at most one active per Shop) and a
 * time-bounded `campaign` (carries start_at/end_at).
 */
enum PromotionType: string
{
    case Base = 'base';
    case Campaign = 'campaign';
}
