<?php

namespace App\Actions\Catalog;

use App\Models\BundleComponent;
use App\Models\StockBalance;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Defines (or redefines) a Variant as a Bundle by its BOM (ADR 0014).
 * A Bundle is virtual: it never holds stock of its own, so a variant
 * with stock cannot become one, and nesting is refused (which also
 * rules out cycles).
 */
class DefineBundle
{
    /**
     * @param  non-empty-list<array{Variant, int}>  $components
     */
    public function handle(Variant $bundle, array $components): void
    {
        if ($components === []) {
            throw new InvalidArgumentException('A Bundle needs at least one component.');
        }

        foreach ($components as [$component, $qty]) {
            if ($component->is($bundle)) {
                throw new InvalidArgumentException('A Bundle cannot be its own component.');
            }
            if ($component->isBundle()) {
                throw new InvalidArgumentException('A bundle cannot contain another bundle (MVP: one level, ADR 0014).');
            }
            if ($qty < 1) {
                throw new InvalidArgumentException('A BOM qty must be at least 1.');
            }
        }

        $holdsStock = StockBalance::query()
            ->where('variant_id', $bundle->id)
            ->where(fn ($query) => $query
                ->where('on_hand', '!=', 0)
                ->orWhere('reserved', '!=', 0)
                ->orWhere('damaged', '!=', 0))
            ->exists();

        if ($holdsStock) {
            throw new InvalidArgumentException('This variant already holds stock — a Bundle is virtual and never has On-Hand of its own (ADR 0014).');
        }

        DB::transaction(function () use ($bundle, $components): void {
            BundleComponent::query()->where('bundle_variant_id', $bundle->id)->delete();

            foreach ($components as [$component, $qty]) {
                BundleComponent::query()->create([
                    'bundle_variant_id' => $bundle->id,
                    'component_variant_id' => $component->id,
                    'qty' => $qty,
                ]);
            }
        });
    }
}
