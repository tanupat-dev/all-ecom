<?php

namespace App\Imports\ChannelTemplate;

use App\Imports\RowImportException;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;

/**
 * TikTok Channel Upload Template filler — Issue #59 (ADR 0019, Phase 9 B).
 *
 * Reference: `ref doc/tiktok/batch upload product tiktok.xlsx`.
 * Target sheet: "Template".
 *
 * Row layout (verified against the real ref doc):
 *   Row 1 = machine keys — PLAIN strings, NO |x|y suffixes (unlike Shopee):
 *     A=category, B=brand, C=product_name, D=product_description,
 *     E=main_image, F=image_2 … M=image_9,
 *     N=property_name_1, O=property_value_1, P=property_1_image,
 *     Q=property_name_2, R=property_value_2,
 *     S=parcel_weight, T=parcel_length, U=parcel_width, V=parcel_height,
 *     W=delivery, X=price, Y=pre_order_time, Z=quantity, AA=seller_sku,
 *     AB=size_chart, AC=cod, AD…AQ=product_property/*, AR…=qualification/*
 *   Row 2 = token row ("V5.0.2 | create_product | metric | category_v2 | …
 *     | <md5> | normal_file | TikTok Shop (0)") — PRESERVED byte-perfect by
 *     WorkbookSurgeon (it is never written; only data rows 6+ are touched).
 *   Row 3 = Thai labels · Row 4 = required markers · Row 5 = instructions.
 *   Data rows start at row 6.
 *
 * Columns owned by this system (ADR 0019 — bounded OMS-with-listing-assist):
 *   brand, product_name, product_description, main_image + image_2..image_9,
 *   property_name_1, property_value_1, parcel_weight, parcel_length,
 *   parcel_width, parcel_height, price, quantity, seller_sku.
 *
 * Brand rule:
 *   "ไม่มีแบรนด์" ONLY when Product.brand is null.  When the seller HAS a
 *   brand, the cell is left blank — TikTok requires a brand selected from its
 *   own minted list (the "Brand" hidden sheet, "Name (id)" format) and we
 *   never fabricate platform tokens (ADR 0019).
 *
 * Money: List Price is stored as integer satang; written as baht string via
 *   Money::toBaht() — never a raw float division (ADR 0015).
 *
 * Stock: max(0, availableAt(location)) — clamp only on export; Available may
 *   be negative internally (CONTEXT.md: Available Stock).
 *
 * Weight: package_weight_g written as grams directly — the template label
 *   says (g), no conversion required.
 *
 * Dimensions: mm → cm (TikTok template unit = cm).
 *
 * Variant option fill (multi-variant Products only):
 *   property_name_1 = "ตัวเลือก", property_value_1 = Variant.name.
 *   Single-variant Products leave both cells blank.
 *
 * Fail-loud required fields (ADR 0005):
 *   Product.name and Product.description are both required for TikTok.
 *   Missing either = RowImportException; that Variant row is held while all
 *   remaining Variants still fill.
 *
 * Image-less Variant behaviour:
 *   NOT a held row — the row fills with all owned columns; image cells
 *   (main_image, image_2…) are simply left empty.  TikTok will accept the
 *   row but drop it to its native Draft state.
 *   NOTE ON NOTICES: surfacing a per-row notice (distinct from an error)
 *   without editing shared infrastructure files is not possible — the
 *   TemplateFillImporter interface returns only column values, and
 *   RunTemplateFillJob has no separate warnings channel.  This limitation is
 *   documented here and reported to the orchestrator; the fill behaviour is
 *   correct as described above.
 *
 * Untouched columns (never written by this filler):
 *   category (baked in by TikTok when the seller downloads the template),
 *   delivery, pre_order_time, size_chart, cod, property_1_image,
 *   property_name_2, property_value_2, all product_property/* and
 *   qualification/* (อย./มอก. compliance is the seller's responsibility).
 *   All hidden sheets (Brand, Category, HiddenStyle, HiddenAttr, …) pass
 *   through WorkbookSurgeon byte-identical — they are never loaded.
 *
 * ListingVariant upsert (platform_sku = master_sku, listing_status = draft,
 *   never downgrade listed) is handled by the shared RunTemplateFillJob.
 */
final class TiktokTemplateFiller implements TemplateFillImporter
{
    private const TARGET_SHEET = 'Template';

    private const DATA_START_ROW = 6;

    // Column key prefixes — plain keys, matching row-1 cell values exactly.
    private const COL_BRAND = 'brand';

    private const COL_PRODUCT_NAME = 'product_name';

    private const COL_PRODUCT_DESC = 'product_description';

    private const COL_MAIN_IMAGE = 'main_image';

    // image_2 … image_9 (8 extra slots: indices 2–9)
    private const IMAGE_EXTRA_COUNT = 8;

    private const COL_PROPERTY_NAME_1 = 'property_name_1';

    private const COL_PROPERTY_VALUE_1 = 'property_value_1';

    private const COL_PARCEL_WEIGHT = 'parcel_weight';

    private const COL_PARCEL_LENGTH = 'parcel_length';

    private const COL_PARCEL_WIDTH = 'parcel_width';

    private const COL_PARCEL_HEIGHT = 'parcel_height';

    private const COL_PRICE = 'price';

    private const COL_QUANTITY = 'quantity';

    private const COL_SELLER_SKU = 'seller_sku';

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
     * Map one Variant to its TikTok template column values.
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

        // Required: product name.
        if (empty($product->name)) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: product name is required for TikTok — fill it before filling the template."
            );
        }

        // Required: description (TikTok rejects blank descriptions).
        if (empty($product->description)) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: product description is required for TikTok — fill it before filling the template."
            );
        }

        // Required: list price.
        if ($variant->list_price === null) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: List Price is not set."
            );
        }

        $isMultiVariant = $productVariants->count() > 1;

        $row = [
            self::COL_PRODUCT_NAME => $product->name,
            self::COL_PRODUCT_DESC => $product->description,
            self::COL_SELLER_SKU => $variant->master_sku,
            self::COL_PRICE => $variant->list_price->toBaht(),
            self::COL_QUANTITY => max(0, $variant->availableAt($location)),
        ];

        // Brand: "ไม่มีแบรนด์" only when null; leave blank when set.
        if ($product->brand === null) {
            $row[self::COL_BRAND] = 'ไม่มีแบรนด์';
        }
        // When brand is set: TikTok requires it to be chosen from its own list
        // ("Brand" hidden sheet, "Name (id)" format) — we never fabricate the
        // platform token, so the cell is intentionally left unwritten.

        // Multi-variant: property_name_1 + property_value_1.
        if ($isMultiVariant) {
            $row[self::COL_PROPERTY_NAME_1] = 'ตัวเลือก';
            $row[self::COL_PROPERTY_VALUE_1] = (string) ($variant->name ?? '');
        }

        // Images — not a held row if absent; just leave cells empty.
        $images = $product->images->sortBy('sort_order')->values();

        if ($images->isNotEmpty()) {
            $row[self::COL_MAIN_IMAGE] = $images->first()->url;

            foreach ($images->slice(1)->values() as $i => $image) {
                if ($i >= self::IMAGE_EXTRA_COUNT) {
                    break;
                }

                // image_2, image_3, …, image_9
                $row['image_'.($i + 2)] = $image->url;
            }
        }

        // Weight: grams → grams (TikTok template unit = g, no conversion).
        if ($variant->package_weight_g !== null) {
            $row[self::COL_PARCEL_WEIGHT] = $variant->package_weight_g;
        }

        // Dimensions: millimetres → cm (TikTok template unit = cm).
        if ($variant->package_length_mm !== null) {
            $row[self::COL_PARCEL_LENGTH] = $variant->package_length_mm / 10;
        }

        if ($variant->package_width_mm !== null) {
            $row[self::COL_PARCEL_WIDTH] = $variant->package_width_mm / 10;
        }

        if ($variant->package_height_mm !== null) {
            $row[self::COL_PARCEL_HEIGHT] = $variant->package_height_mm / 10;
        }

        return $row;
    }
}
