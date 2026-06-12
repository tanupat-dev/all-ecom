<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Variant;
use LogicException;

/**
 * Catalogue-master round-trip importer (ROADMAP Phase 9, ADR 0019).
 *
 * Keys on master_sku (tenant-scoped via BelongsToTenant). An unknown
 * master_sku is fail-loud (ADR 0005) — the row is held and surfaced in the
 * error report; the importer never auto-creates Variants or silently defaults.
 *
 * Blank-cell semantics (WYSIWYG — documented here because it is a design
 * decision, not an implementation detail):
 *   A blank cell means "set this field to null". The file is the full truth
 *   of these nullable fields — what the seller sees in the exported file IS
 *   the catalogue master. To clear a field, leave the cell empty; to keep a
 *   value, keep the value. This is the only unambiguous convention: "leave
 *   unchanged" would make the importer stateful and break idempotency.
 *
 * Exception: product_name is not nullable (Product.name is NOT NULL) —
 * a blank product_name is fail-loud.
 *
 * Idempotent: re-importing the same file with the same values is a no-op.
 */
class CatalogueMasterImporter implements Importer
{
    /**
     * Map one header-keyed spreadsheet row to its upsertable shape.
     * Fail-loud (ADR 0005) on: missing/empty master_sku, unknown master_sku,
     * empty product_name, and non-integer dimension values.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function mapRow(array $row, int $rowNumber): array
    {
        // ── master_sku: required, is the lookup key ─────────────────────────
        $sku = is_scalar($row['master_sku'] ?? null) ? trim((string) $row['master_sku']) : '';

        if ($sku === '') {
            throw new RowImportException("Row {$rowNumber}: master_sku is required.");
        }

        // Tenant-scoped via BelongsToTenant global scope — only searches
        // within the current tenant (ADR 0011).
        $variant = Variant::query()->where('master_sku', $sku)->first()
            ?? throw new RowImportException(
                "Unknown Master SKU [{$sku}] — create the product first or correct the SKU (ADR 0005)."
            );

        // ── product_name: required — Product.name is NOT NULL ───────────────
        $productName = is_scalar($row['product_name'] ?? null) ? trim((string) $row['product_name']) : '';

        if ($productName === '') {
            throw new RowImportException("Row {$rowNumber}: product_name is required (Product.name cannot be null).");
        }

        // ── nullable text fields: blank = null (WYSIWYG) ───────────────────
        $englishName = $this->nullableString($row['english_name'] ?? null);
        $description = $this->nullableString($row['description'] ?? null);
        $brand = $this->nullableString($row['brand'] ?? null);
        $variantName = $this->nullableString($row['variant_name'] ?? null);

        // ── nullable dimensions: blank = null, else non-negative integer ────
        $weightG = $this->nullableNonNegativeInt($row['package_weight_g'] ?? null, 'package_weight_g', $rowNumber);
        $widthMm = $this->nullableNonNegativeInt($row['package_width_mm'] ?? null, 'package_width_mm', $rowNumber);
        $lengthMm = $this->nullableNonNegativeInt($row['package_length_mm'] ?? null, 'package_length_mm', $rowNumber);
        $heightMm = $this->nullableNonNegativeInt($row['package_height_mm'] ?? null, 'package_height_mm', $rowNumber);

        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'product_name' => $productName,
            'english_name' => $englishName,
            'description' => $description,
            'brand' => $brand,
            'variant_name' => $variantName,
            'package_weight_g' => $weightG,
            'package_width_mm' => $widthMm,
            'package_length_mm' => $lengthMm,
            'package_height_mm' => $heightMm,
        ];
    }

    /**
     * Persist one chunk of mapped rows idempotently. Each row updates
     * Product (name, english_name, description, brand) and Variant (name,
     * package_*) via Eloquent, both scoped to the current tenant.
     *
     * @param  list<array<string, mixed>>  $chunk
     */
    public function upsertChunk(array $chunk): void
    {
        foreach ($chunk as $row) {
            $variantId = $row['variant_id'] ?? null;
            $productId = $row['product_id'] ?? null;

            if (! is_int($variantId) || ! is_int($productId)) {
                throw new LogicException('CatalogueMasterImporter: mapRow shape drifted — expected int variantId and productId.');
            }

            // Tenant-scoped by BelongsToTenant — safe to update by PK.
            Product::query()->where('id', $productId)->update([
                'name' => $row['product_name'],
                'english_name' => $row['english_name'],
                'description' => $row['description'],
                'brand' => $row['brand'],
            ]);

            Variant::query()->where('id', $variantId)->update([
                'name' => $row['variant_name'],
                'package_weight_g' => $row['package_weight_g'],
                'package_width_mm' => $row['package_width_mm'],
                'package_length_mm' => $row['package_length_mm'],
                'package_height_mm' => $row['package_height_mm'],
            ]);
        }
    }

    /**
     * Returns null for blank/empty cells; trims non-blank strings.
     * WYSIWYG blank-cell semantics: blank in the file = null in the DB.
     */
    private function nullableString(mixed $cell): ?string
    {
        if (! is_scalar($cell) || trim((string) $cell) === '') {
            return null;
        }

        return trim((string) $cell);
    }

    /**
     * Returns null for blank cells; parses non-negative integers from
     * numeric cells (accepts int or float-valued-as-int from xlsx — e.g.
     * OpenSpout returns 350 from a numeric xlsx cell, never "350.5" from an
     * unsignedInteger column). Fail-loud on non-numeric or negative values.
     *
     * @throws RowImportException
     */
    private function nullableNonNegativeInt(mixed $cell, string $field, int $rowNumber): ?int
    {
        if ($cell === null || (is_string($cell) && trim($cell) === '')) {
            return null;
        }

        if (! is_numeric($cell)) {
            $display = is_scalar($cell) ? (string) $cell : gettype($cell);
            throw new RowImportException(
                "Row {$rowNumber}: {$field} must be a non-negative integer, got [{$display}]."
            );
        }

        $int = (int) $cell;

        if ($int < 0) {
            throw new RowImportException(
                "Row {$rowNumber}: {$field} must be non-negative, got [{$int}]."
            );
        }

        return $int;
    }
}
