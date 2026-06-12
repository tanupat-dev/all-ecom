<?php

namespace App\Imports\ChannelTemplate;

use App\Imports\RowImportException;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shopee Channel Upload Template filler — Issue #57 (ADR 0019, Phase 9 B).
 *
 * Reference: `ref doc/shopee/batch upload product shopee.xlsx`.
 * Target sheet: "แบบฟอร์มการลงสินค้า".
 * Row 1 = machine keys with |x|y suffixes — anchor on prefix before first `|`.
 * Row 2 = token row (preserved by WorkbookSurgeon — never touched).
 * Rows 3–6 = preamble (Thai labels / required / instructions / format hints).
 * Data rows start at row 7.
 *
 * Only the columns this system authoritatively owns are filled (ADR 0019 —
 * bounded OMS-with-listing-assist, NOT a PIM). Category-specific columns,
 * purchase-limit cols, GTIN, size chart, channel/pre-order/reason columns
 * are left completely untouched.
 *
 * Money: List Price is stored as integer satang; written as baht string via
 * Money::toBaht() — never a raw float division (ADR 0015).
 *
 * Stock: max(0, availableAt(location)) — mirrors ExportShopStock rule (clamp
 * only on export; Available may be negative inside, CONTEXT.md: Available
 * Stock).
 *
 * Variant option fill: only when the Product has >1 Variant.
 * Parent SKU fill: only when the Product has >1 Variant (use first
 * Variant's master_sku as parent identifier, matching Shopee convention).
 *
 * Fail-loud required fields (ADR 0005): Product.description (Shopee requires
 * it for a valid listing). Missing description = RowImportException; that
 * Variant row is held but all others still fill.
 *
 * Image-less Variant: NOT a held row; image cells are left empty.
 */
final class ShopeeTemplateFiller implements TemplateFillImporter
{
    private const TARGET_SHEET = 'แบบฟอร์มการลงสินค้า';

    private const DATA_START_ROW = 7;

    // Column key prefixes (prefix before first `|` in row 1 of the template).
    private const COL_PRODUCT_NAME = 'ps_product_name';

    private const COL_PRODUCT_DESC = 'ps_product_description';

    private const COL_SKU_PARENT = 'ps_sku_parent_short';

    private const COL_VARIATION_TITLE = 'et_title_variation_1';

    private const COL_VARIATION_OPTION = 'et_title_option_for_variation_1';

    private const COL_PRICE = 'ps_price';

    private const COL_STOCK = 'ps_stock';

    private const COL_SKU_SHORT = 'ps_sku_short';

    private const COL_COVER_IMAGE = 'ps_item_cover_image';

    // ps_item_image_1 … ps_item_image_8
    private const IMAGE_EXTRA_COUNT = 8;

    private const COL_WEIGHT = 'ps_weight';

    private const COL_LENGTH = 'ps_length';

    private const COL_WIDTH = 'ps_width';

    private const COL_HEIGHT = 'ps_height';

    public function targetSheet(): string
    {
        return self::TARGET_SHEET;
    }

    public function resolveTargetSheet(string $xlsxPath): string
    {
        return $this->targetSheet();
    }

    public function keySheet(string $targetSheet): string
    {
        return $targetSheet;
    }

    public function keyRow(): int
    {
        return 1;
    }

    public function dataStartRow(): int
    {
        return self::DATA_START_ROW;
    }

    /**
     * Map one Variant to its Shopee template column values.
     *
     * @param  Collection<int, Variant>  $productVariants
     * @return array<string, string|int|float>
     *
     * @throws RowImportException
     */
    public function mapVariant(
        Variant $variant,
        Shop $shop,
        Location $location,
        Collection $productVariants,
    ): array {
        $product = $variant->product;

        // Required: description (Shopee rejects blank descriptions).
        if (empty($product->description)) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: product description is required for Shopee — fill it before filling the template."
            );
        }

        // Required: list price.
        if ($variant->list_price === null) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: List Price is not set."
            );
        }

        $isMultiVariant = $productVariants->count() > 1;
        $firstVariantSku = $productVariants->sortBy('id')->first()->master_sku ?? '';

        $row = [
            self::COL_PRODUCT_NAME => $product->name,
            self::COL_PRODUCT_DESC => $product->description,
            self::COL_SKU_SHORT => $variant->master_sku,
            self::COL_PRICE => $variant->list_price->toBaht(),
            self::COL_STOCK => max(0, $variant->availableAt($location)),
        ];

        // Parent SKU — only when product has multiple variants.
        if ($isMultiVariant) {
            $row[self::COL_SKU_PARENT] = $firstVariantSku;
            $row[self::COL_VARIATION_TITLE] = 'ตัวเลือก';
            $row[self::COL_VARIATION_OPTION] = (string) ($variant->name ?? '');
        }

        // Images — not a held row if absent; just leave empty.
        $images = $product->images->sortBy('sort_order')->values();

        if ($images->isNotEmpty()) {
            $row[self::COL_COVER_IMAGE] = $images->first()->url;

            foreach ($images->slice(1)->values() as $i => $image) {
                if ($i >= self::IMAGE_EXTRA_COUNT) {
                    break;
                }

                $row['ps_item_image_'.($i + 1)] = $image->url;
            }
        }

        // Weight: grams → kg (Shopee template unit = kg).
        if ($variant->package_weight_g !== null) {
            $row[self::COL_WEIGHT] = $variant->package_weight_g / 1000;
        }

        // Dimensions: millimetres → cm (Shopee template unit = cm).
        if ($variant->package_length_mm !== null) {
            $row[self::COL_LENGTH] = $variant->package_length_mm / 10;
        }

        if ($variant->package_width_mm !== null) {
            $row[self::COL_WIDTH] = $variant->package_width_mm / 10;
        }

        if ($variant->package_height_mm !== null) {
            $row[self::COL_HEIGHT] = $variant->package_height_mm / 10;
        }

        return $row;
    }
}
