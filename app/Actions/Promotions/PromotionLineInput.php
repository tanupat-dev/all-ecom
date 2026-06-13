<?php

namespace App\Actions\Promotions;

use App\Models\ListingVariant;
use App\Support\Money;
use App\Support\PercentOff;
use InvalidArgumentException;
use LogicException;

/**
 * One line of a Promotion as the seller specifies it: a Listing-Variant plus a
 * Deal Price entered EITHER as an explicit Money (baht) OR as a "% off" of the
 * Variant's List Price (CONTEXT.md: Deal Price; ADR 0021).
 *
 * The percentage is converted to a satang Money at this input boundary so a
 * rate is never persisted — Deal Price is always integer satang (ADR 0015).
 */
final readonly class PromotionLineInput
{
    private function __construct(
        public ListingVariant $listingVariant,
        private ?Money $dealPrice,
        private ?PercentOff $percentOff,
    ) {}

    /**
     * The seller typed an explicit Deal Price (already baht → satang Money).
     */
    public static function dealPrice(ListingVariant $listingVariant, Money $dealPrice): self
    {
        return new self($listingVariant, $dealPrice, null);
    }

    /**
     * The seller typed a "% off" — resolved against the Variant's List Price.
     */
    public static function percentOff(ListingVariant $listingVariant, PercentOff $percentOff): self
    {
        return new self($listingVariant, null, $percentOff);
    }

    /**
     * The Deal Price as integer-satang Money. For a percentage, convert against
     * the Variant's List Price; fail loud if the Variant has no List Price (an
     * unmapped value is never silently defaulted — ADR 0005).
     */
    public function resolveDealPrice(): Money
    {
        if ($this->dealPrice !== null) {
            return $this->dealPrice;
        }

        $percentOff = $this->percentOff;

        if ($percentOff === null) {
            throw new LogicException('A PromotionLineInput must carry either a Deal Price or a percent-off.');
        }

        $listPrice = $this->listingVariant->variant()->firstOrFail()->list_price;

        if ($listPrice === null) {
            throw new InvalidArgumentException(
                "Cannot resolve a percent-off Deal Price: Variant [{$this->listingVariant->variant_id}] has no List Price."
            );
        }

        return $percentOff->applyTo($listPrice);
    }
}
