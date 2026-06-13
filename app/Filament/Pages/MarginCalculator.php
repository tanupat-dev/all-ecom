<?php

namespace App\Filament\Pages;

use App\Actions\Pricing\ComputeMargin;
use App\Models\ListingVariant;
use App\Support\MarginTarget;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * Margin Calculator (CONTEXT.md: Margin Calculator; Issue #76) — per
 * Listing-Variant price/margin tool. Two directions: a target profit (% of
 * cost OR fixed THB) → a recommended Effective Price, and an Effective Price →
 * the implied profit. The money math lives in ComputeMargin; this page only
 * binds inputs and renders the result in baht.
 *
 * Gated on `cost.view` (ADR 0012): the recommendation inherently reveals
 * cost-derived margin, so a user without `cost.view` cannot reach the page at
 * all (no recommendation, no implied profit, no cost leak).
 */
class MarginCalculator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $title = 'คำนวณราคาขาย (Margin Calculator)';

    protected static ?string $navigationLabel = 'Margin Calculator';

    protected string $view = 'filament.pages.margin-calculator';

    /** The Listing-Variant to price (its Shop's Fee Profile drives the math). */
    public ?int $listingVariantId = null;

    /** 'forward' (target → price) or 'symmetric' (price → profit). */
    public string $direction = 'forward';

    /** 'percent' (of cost) or 'fixed' (THB) — forward direction only. */
    public string $targetType = 'percent';

    /** Forward input: a percent ("30") or a baht amount ("50.00"). */
    public string $targetValue = '';

    /** Symmetric input: an Effective Price in baht ("144.44"). */
    public string $effectivePriceBaht = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->checkPermissionTo('cost.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'listingVariants' => ListingVariant::query()->with('variant')->get(),
            'result' => $this->result(),
        ];
    }

    /**
     * @return array{recommended_price: ?Money, implied_profit: ?Money, error: ?string}|null
     */
    private function result(): ?array
    {
        if ($this->listingVariantId === null) {
            return null;
        }

        $listingVariant = ListingVariant::query()->find($this->listingVariantId);

        if ($listingVariant === null) {
            return null;
        }

        $calculator = app(ComputeMargin::class);

        try {
            if ($this->direction === 'symmetric') {
                if ($this->effectivePriceBaht === '') {
                    return null;
                }

                $price = Money::fromBaht($this->effectivePriceBaht);

                return [
                    'recommended_price' => $price,
                    'implied_profit' => $calculator->impliedProfit($listingVariant, $price),
                    'error' => null,
                ];
            }

            if ($this->targetValue === '') {
                return null;
            }

            $target = $this->targetType === 'fixed'
                ? MarginTarget::fixed(Money::fromBaht($this->targetValue))
                // A percent string ("3.21") parses to basis points exactly via
                // the satang parser (321), no float (ADR 0015).
                : MarginTarget::percentOfCost(Money::fromBaht($this->targetValue)->satang);

            $price = $calculator->recommendedPrice($listingVariant, $target);

            return [
                'recommended_price' => $price,
                'implied_profit' => $calculator->impliedProfit($listingVariant, $price),
                'error' => null,
            ];
        } catch (LogicException $e) {
            // Fail-loud (no cost / fees ≥ 100%): surface the message, never a
            // silently wrong number. InvalidArgumentException (bad baht input)
            // is a subclass of LogicException, so it is caught here too.
            return [
                'recommended_price' => null,
                'implied_profit' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
