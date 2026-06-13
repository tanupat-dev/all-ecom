<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Models\PromotionLine;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a Promotion (base or campaign) with its Promotion Lines in one
 * transaction (CONTEXT.md: Promotion, Promotion Line; ADR 0021).
 *
 * Modelling choice (ADR 0021 + CONTEXT.md): a Promotion has no shop_id. Its
 * Shop scope is the set of Shops its lines touch (each line's listing_variant
 * carries shop_id), so one Promotion may span Shops while the "≤1 active base
 * Promotion per Shop" invariant is enforced per the touched Shops below.
 *
 * Fail-loud invariants (ADR 0005 posture; enforced here, not in the UI):
 *  - a campaign requires both start_at and end_at with start_at < end_at;
 *  - a base must carry no time window (it is always active);
 *  - a base may not touch a Shop that already has an active base Promotion;
 *  - no two campaign lines on one Listing-Variant may overlap in time
 *    (the MVP one-active-line invariant; CONTEXT.md);
 *  - at most one line per Listing-Variant per Promotion;
 *  - Deal Price is integer satang and never negative (ADR 0015).
 */
class CreatePromotion
{
    /**
     * @param  list<PromotionLineInput>  $lines
     */
    public function handle(
        PromotionType $type,
        string $name,
        array $lines,
        ?DateTimeInterface $startAt = null,
        ?DateTimeInterface $endAt = null,
    ): Promotion {
        $this->guardWindow($type, $startAt, $endAt);

        if ($lines === []) {
            throw new InvalidArgumentException('A Promotion needs at least one Promotion Line (CONTEXT.md: Promotion).');
        }

        $this->guardNoDuplicateLines($lines);

        return DB::transaction(function () use ($type, $name, $lines, $startAt, $endAt): Promotion {
            if ($type === PromotionType::Base) {
                $this->guardSingleActiveBasePerShop($lines);
            }

            if ($type === PromotionType::Campaign) {
                // guardWindow already proved both bounds are non-null here;
                // assert() narrows the types for the analyser.
                assert($startAt !== null && $endAt !== null);
                $this->guardNoOverlappingCampaign($lines, $startAt, $endAt);
            }

            $promotion = Promotion::query()->create([
                'name' => $name,
                'type' => $type,
                'start_at' => $startAt,
                'end_at' => $endAt,
            ]);

            foreach ($lines as $line) {
                $dealPrice = $line->resolveDealPrice();

                if ($dealPrice->isNegative()) {
                    throw new InvalidArgumentException('Deal Price cannot be negative (ADR 0015).');
                }

                $promotion->lines()->create([
                    'listing_variant_id' => $line->listingVariant->id,
                    'deal_price' => $dealPrice,
                ]);
            }

            return $promotion->load('lines');
        });
    }

    private function guardWindow(PromotionType $type, ?DateTimeInterface $startAt, ?DateTimeInterface $endAt): void
    {
        if ($type === PromotionType::Campaign) {
            if ($startAt === null || $endAt === null) {
                throw new InvalidArgumentException('A campaign Promotion requires both start_at and end_at (CONTEXT.md: Promotion).');
            }

            if ($startAt >= $endAt) {
                throw new InvalidArgumentException('A campaign Promotion requires start_at < end_at.');
            }

            return;
        }

        // base: always active — it must carry no time window.
        if ($startAt !== null || $endAt !== null) {
            throw new InvalidArgumentException('A base Promotion must not carry a time window (CONTEXT.md: Promotion).');
        }
    }

    /**
     * @param  list<PromotionLineInput>  $lines
     */
    private function guardNoDuplicateLines(array $lines): void
    {
        $ids = array_map(static fn (PromotionLineInput $line): int => $line->listingVariant->id, $lines);

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('A Promotion may carry at most one line per Listing-Variant (CONTEXT.md: Promotion Line).');
        }
    }

    /**
     * At most one active base Promotion per Shop (CONTEXT.md). A base is always
     * active, so if any line's Shop already carries a base Promotion line, fail
     * loud. Tenant-scoped automatically via BelongsToTenant + RLS.
     *
     * @param  list<PromotionLineInput>  $lines
     */
    private function guardSingleActiveBasePerShop(array $lines): void
    {
        $shopIds = array_values(array_unique(
            array_map(static fn (PromotionLineInput $line): int => $line->listingVariant->shop_id, $lines)
        ));

        $conflict = PromotionLine::query()
            ->whereHas('promotion', static function (Builder $query): void {
                $query->where('type', PromotionType::Base->value);
            })
            ->whereHas('listingVariant', static function (Builder $query) use ($shopIds): void {
                $query->whereIn('shop_id', $shopIds);
            })
            ->exists();

        if ($conflict) {
            throw new InvalidArgumentException(
                'A Shop already has an active base Promotion (CONTEXT.md: at most one active base Promotion per Shop).'
            );
        }
    }

    /**
     * The MVP one-active-line invariant (CONTEXT.md): at any time T a
     * Listing-Variant has exactly one active Promotion Line. A base always
     * coexists with at most one in-window campaign (resolution picks the
     * campaign — fine), but two campaign lines on the same Listing-Variant must
     * not overlap. Two half-open windows [s1,e1) and [s2,e2) overlap iff
     * s1 < e2 AND s2 < e1 — back-to-back windows that merely touch at a
     * boundary (e1 == s2) do NOT overlap (the end is exclusive). Fail loud
     * (ADR 0005 posture) before any insert.
     *
     * @param  list<PromotionLineInput>  $lines
     */
    private function guardNoOverlappingCampaign(array $lines, DateTimeInterface $startAt, DateTimeInterface $endAt): void
    {
        $listingVariantIds = array_values(array_unique(
            array_map(static fn (PromotionLineInput $line): int => $line->listingVariant->id, $lines)
        ));

        $overlap = PromotionLine::query()
            ->whereIn('listing_variant_id', $listingVariantIds)
            ->whereHas('promotion', static function (Builder $query) use ($startAt, $endAt): void {
                $query->where('type', PromotionType::Campaign->value)
                    ->where('start_at', '<', $endAt)
                    ->where('end_at', '>', $startAt);
            })
            ->exists();

        if ($overlap) {
            throw new InvalidArgumentException(
                'A campaign Promotion Line already overlaps this window on the same Listing-Variant '
                .'(CONTEXT.md: exactly one active Promotion Line at T — no overlapping campaigns).'
            );
        }
    }
}
