<?php

namespace App\Imports;

use App\Enums\ListingStatus;
use App\Listings\PlatformSkuConflictException;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Variant;
use LogicException;

/**
 * Lazada "All product" export (reference: `ref doc/lazada/All product
 * lazada.xlsx`). Rebuilds Listing Coverage from reality — creates/updates
 * Listing + ListingVariant rows with listing_status = listed (ground truth)
 * and populates the (Shop, Platform SKU) → Variant resolution map
 * (CONTEXT.md: Listing Coverage, Listing Status; ADR 0019 + ADR 0005).
 *
 * The pipeline reads only the first sheet (`template`). The file's three
 * other sheets (`template_hide`, `สถานะ`, `global_hide`) are metadata/config
 * only — the pipeline break-after-first-sheet contract handles this silently.
 *
 * Column mapping anchors on Row 1's mixed English/Thai headers. The
 * identifiers that matter are purely English: `Product ID` (col 0) and
 * `SellerSKU` (col 12). Preamble rows 2–4 (required/optional markers, field
 * descriptions, constraints) are identified by a non-numeric `Product ID`
 * and silently skipped — not counted as import errors.
 *
 * ADR 0005 boundary: an unmatched SellerSKU is fail-loud — the row is held
 * and surfaced; the importer never auto-creates Variants or silently skips.
 * Re-import is idempotent on (tenant, shop, platform_sku); draft→listed on
 * import from Platform reality (ground truth, ADR 0019).
 */
class LazadaAllProductImporter extends PlatformFileImporter
{
    /**
     * Column names as they appear in the file's Row 1 (Sheet 1: template).
     * `Product ID` identifies the Lazada listing (numeric on data rows).
     * `SellerSKU` is the seller-set Platform SKU used for Variant resolution
     * (CONTEXT.md: Platform SKU — many-to-one allowed, one SKU → two Variants
     * is the only illegal case).
     */
    private const COL_PRODUCT_ID = 'Product ID';

    private const COL_SELLER_SKU = 'SellerSKU';

    /**
     * Sentinel key returned for Lazada preamble rows (rows 2–4: required/
     * optional markers, field descriptions, constraints). These are structural
     * — not import errors — so the sentinel lets upsertChunk filter them
     * without surfacing any errors.
     */
    private const PREAMBLE_KEY = '_preamble';

    /**
     * Map one spreadsheet row to its upsertable shape (ADR 0005: fail-loud).
     *
     * A data row always has a purely-numeric Lazada product_id in `Product ID`.
     * Rows 2–4 are preamble — returned as a sentinel, never an error.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function mapRow(array $row, int $rowNumber): array
    {
        $productId = $this->cell($row, self::COL_PRODUCT_ID);

        // Lazada "All product" file wraps data in 3 preamble rows (required/
        // optional markers, field descriptions, constraints) before the first
        // data row. A data row always has a purely-numeric product_id.
        if ($productId === '' || ! ctype_digit($productId)) {
            return [self::PREAMBLE_KEY => true];
        }

        $platformSku = $this->cell($row, self::COL_SELLER_SKU);

        if ($platformSku === '') {
            throw new RowImportException(
                "Row {$rowNumber}: SellerSKU is empty — cannot build the (Shop, Platform SKU) → Variant mapping."
            );
        }

        // Primary match: Variant.master_sku = SellerSKU from the file.
        // By default the seller sets the Platform SKU = Master SKU; if they
        // have not, this is an unmatched row (ADR 0005: fail-loud, never guess).
        $variant = Variant::query()
            ->where('master_sku', $platformSku)
            ->first();

        if ($variant === null) {
            throw new RowImportException(
                "Master SKU [{$platformSku}] is not in the system — import the product catalogue first or align the Platform SKU with the Master SKU (ADR 0005)."
            );
        }

        // Guard the resolution function: one (Shop, Platform SKU) → exactly
        // one Variant. Conflict = same SKU already resolves to a different
        // Variant → fail-loud (CONTEXT.md: Platform SKU).
        $conflict = ListingVariant::query()
            ->conflictingWith($this->shop()->id, $platformSku, $variant->id)
            ->first();

        if ($conflict !== null) {
            $conflictVariant = $conflict->variant()->firstOrFail();

            throw new RowImportException(
                PlatformSkuConflictException::for($platformSku, $conflictVariant->master_sku)->getMessage()
            );
        }

        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'platform_sku' => $platformSku,
        ];
    }

    /**
     * Persist one chunk of mapped rows idempotently. Runs inside a DB
     * transaction (enforced by the pipeline in RunImportJob).
     *
     * Dedup key: (listing_id, variant_id) — the DB unique constraint on
     * listing_variants. Re-import of the same row is a no-op for `listed`
     * rows and a draft→listed flip for `draft` rows.
     *
     * @param  list<array<string, mixed>>  $chunk
     */
    public function upsertChunk(array $chunk): void
    {
        // Preamble sentinels are structural, not invalid — filter silently.
        $rows = array_values(array_filter(
            $chunk,
            static fn (array $row): bool => ! ($row[self::PREAMBLE_KEY] ?? false),
        ));

        if ($rows === []) {
            return;
        }

        $shopId = $this->shop()->id;

        foreach ($rows as $row) {
            $variantId = $row['variant_id'] ?? null;
            $productId = $row['product_id'] ?? null;
            $platformSku = $row['platform_sku'] ?? null;

            if (! is_int($variantId) || ! is_int($productId) || ! is_string($platformSku)) {
                throw new LogicException('mapRow shape drifted — expected int variantId/productId and string platformSku.');
            }

            // Find or create the Listing (Product × Shop projection layer —
            // ADR 0010). firstOrCreate is tenant-scoped via BelongsToTenant.
            $listing = Listing::query()->firstOrCreate(
                ['shop_id' => $shopId, 'product_id' => $productId],
            );

            // Upsert the ListingVariant keyed on (listing_id, variant_id).
            // Ground truth from Platform reality: listing_status = listed.
            // Draft rows (from a Channel Upload Template fill) flip to listed.
            $listingVariant = ListingVariant::query()
                ->where('listing_id', $listing->id)
                ->where('variant_id', $variantId)
                ->first();

            if ($listingVariant !== null) {
                $listingVariant->update([
                    'platform_sku' => $platformSku,
                    'listing_status' => ListingStatus::Listed,
                ]);
            } else {
                $listing->variants()->create([
                    'shop_id' => $shopId,
                    'variant_id' => $variantId,
                    'platform_sku' => $platformSku,
                    'listing_status' => ListingStatus::Listed,
                ]);
            }
        }
    }
}
