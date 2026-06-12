<?php

namespace App\Imports\ChannelTemplate;

use App\Imports\RowImportException;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use DOMDocument;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use ZipArchive;

/**
 * Lazada Channel Upload Template filler — Issue #58 (ADR 0019, Phase 9 B).
 *
 * Reference: `ref doc/lazada/batch upload product lazada.xlsx`.
 *
 * ── Lazada template structure ──────────────────────────────────────────────
 * The Lazada batch-upload xlsx uses a DIFFERENT structure from Shopee:
 *   - INDEX sheet: lists all leaf-category tab names (one per row, from row 2)
 *   - Visible category sheet (e.g. "รองเท้าผ้าใบผู้ชาย"):
 *       Row 1 = Thai human-readable column labels
 *       Rows 2–4 = required/optional indicators + explainer preamble
 *       Data rows start at row 5.
 *   - Paired hidden sheet ("<category>_hide", e.g. "รองเท้าผ้าใบผู้ชาย_hide"):
 *       Rows 1–2 = empty (structural rows)
 *       Row 3  = machine-key names at the SAME column positions as the visible sheet.
 *   - สถานะ sheet (status; leave untouched)
 *   - global_hide sheet (metadata + md5 token; leave completely untouched — byte-identity required)
 *
 * ── Production engine path ─────────────────────────────────────────────────
 * resolveTargetSheet() calls detectFromWorkbook() on the uploaded file to
 * discover the dynamic category sheet name. keySheet() returns
 * "<targetSheet>_hide" so RunTemplateFillJob reads machine keys from the
 * hidden companion sheet. keyRow() returns 3 so WorkbookSurgeon scans
 * physical row 3 (rows 1–2 are structural empty rows). All cells in the
 * _hide sheet and global_hide sheet are written never by this filler —
 * byte-identity is preserved.
 *
 * ── Category-sheet discovery ──────────────────────────────────────────────
 * Because different Lazada categories produce differently-named sheets, the
 * category sheet name CANNOT be hard-coded (unlike Shopee's fixed name).
 * resolveTargetSheet() is called once by RunTemplateFillJob and both sets
 * $this->categorySheet and returns the name.
 *
 * ── Single-category v1 ────────────────────────────────────────────────────
 * Only one leaf-category sheet (one INDEX listing → one `<x>_hide` pair) is
 * supported. detectFromWorkbook() fails loud with a Thai message if the
 * workbook contains more than one category sheet. Multi-sheet support is
 * additive for a future phase.
 *
 * ── Owned machine keys (from the _hide sheet's row 3) ────────────────────
 * Written per Variant row into the VISIBLE category sheet:
 *   productNoForBatch   running group number per Product (groups multi-variant rows)
 *   title.th_TH         Product.name
 *   title.en_TH         Product.english_name (skipped if null)
 *   mainImage.0..7      Product Image URLs in sort order (up to 8)
 *   marketImages.1:1    primary image URL (square 1:1 crop for search card)
 *   description         Product.description
 *   catProperty.p-20000 Product.brand — ONLY when that exact column key exists in the
 *                       category template AND brand is non-null; never guess another key
 *                       (catProperty.* keys are category-scoped — ADR 0019)
 *   sku.SellerSku       master_sku  (sku.shop_sku is Lazada's internal key — left alone)
 *   sku.price           List Price satang→baht via Money::toBaht() (ADR 0015)
 *   sku.quantity        max(0, $variant->availableAt($location)) — clamp only on export
 *   sku.package_weight  package_weight_g ÷ 1000 → kg
 *   sku.package_length  package_length_mm ÷ 10  → cm
 *   sku.package_width   package_width_mm  ÷ 10  → cm
 *   sku.package_height  package_height_mm ÷ 10  → cm
 *   sku.images.0..7     variant-scoped images if any, else product-level images
 *
 * ── Fail-loud required fields (ADR 0005) ──────────────────────────────────
 * Missing Product.name or Product.description = RowImportException (row held,
 * other rows still fill). Missing Product Image = RowImportException (Lazada
 * REQUIRES a main image URL; unlike Shopee where image-less rows are allowed).
 * Good rows always fill even when some rows are held.
 *
 * ── NOT filled (leave completely untouched) ───────────────────────────────
 * catId, catProperty.* (all except p-20000 when it exists), saleProp.*
 * (category-scoped option keys — the seller must select variant attributes per
 * Lazada's category ontology), sku.shop_sku (Lazada's internal platform SKU),
 * sku.special_price.* / sku.campaignPrice.* (Deal Price = Phase 7),
 * warrantyPolicy / warrantyPeriod / warrantyType, radioDangerousGoods,
 * deliveryStandard, packageContent, currencyCode, originalLocalName,
 * newVideo, everything in _hide / สถานะ / global_hide sheets.
 *
 * ── ListingVariant upsert ─────────────────────────────────────────────────
 * platform_sku = master_sku, listing_status = draft. Never downgrade a row
 * already in `listed` status — the Platform export is ground truth (ADR 0019).
 * Mirror Shopee filler mechanics exactly.
 */
final class LazadaTemplateFiller implements TemplateFillImporter
{
    /** Real data start row — rows 1–4 are preamble (verified against ref doc). */
    private const DATA_START_ROW = 5;

    // ── Machine-key constants (anchored on _hide sheet row 3) ─────────────

    private const COL_GROUP_NO = 'productNoForBatch';

    private const COL_TITLE_TH = 'title.th_TH';

    private const COL_TITLE_EN = 'title.en_TH';

    private const COL_DESCRIPTION = 'description';

    private const COL_BRAND = 'catProperty.p-20000';

    private const COL_MARKET_IMAGE = 'marketImages.1:1';

    private const COL_SELLER_SKU = 'sku.SellerSku';

    private const COL_PRICE = 'sku.price';

    private const COL_QUANTITY = 'sku.quantity';

    private const COL_WEIGHT = 'sku.package_weight';

    private const COL_LENGTH = 'sku.package_length';

    private const COL_WIDTH = 'sku.package_width';

    private const COL_HEIGHT = 'sku.package_height';

    /** Max main-image columns: mainImage.0 … mainImage.7 */
    private const MAIN_IMAGE_COUNT = 8;

    /** Max SKU-image columns: sku.images.0 … sku.images.7 */
    private const SKU_IMAGE_COUNT = 8;

    // ── Instance state ────────────────────────────────────────────────────

    /**
     * The leaf-category sheet name (e.g. "รองเท้าผ้าใบผู้ชาย"). Set via
     * setTargetSheet() after detectFromWorkbook() is called, before the job runs.
     */
    private string $categorySheet = '';

    /**
     * product_id → 1-based group number for productNoForBatch.
     * Accumulated across mapVariant() calls within one job run.
     *
     * @var array<int, int>
     */
    private array $groupNumberMap = [];

    private int $nextGroupNumber = 1;

    // ── Static workbook inspection ────────────────────────────────────────

    /**
     * Detect the single leaf-category sheet name from an uploaded Lazada xlsx.
     *
     * A "leaf category" sheet is one that:
     *   - appears in the workbook as a non-hidden visible sheet, AND
     *   - has a corresponding "<name>_hide" sheet, AND
     *   - is NOT one of the structural sheets: INDEX, สถานะ, global_hide.
     *
     * Fail-loud (RowImportException) if zero or more than one category is found.
     *
     * @throws RowImportException
     */
    public static function detectFromWorkbook(string $xlsxPath): string
    {
        $z = new ZipArchive;

        if ($z->open($xlsxPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Cannot open xlsx: {$xlsxPath}");
        }

        $workbookXml = $z->getFromName('xl/workbook.xml');
        $z->close();

        if ($workbookXml === false) {
            throw new RuntimeException('Not a valid xlsx: missing xl/workbook.xml.');
        }

        $dom = new DOMDocument;
        $dom->loadXML($workbookXml);

        $sheetNames = [];

        foreach ($dom->getElementsByTagNameNS('*', 'sheet') as $sheet) {
            /** @var \DOMElement $sheet */
            $sheetNames[] = $sheet->getAttribute('name');
        }

        // Structural sheets that are NOT leaf categories.
        $reservedNames = ['INDEX', 'สถานะ', 'global_hide'];

        // Collect visible sheets that have a corresponding <name>_hide partner.
        $categorySheets = [];

        foreach ($sheetNames as $name) {
            if (in_array($name, $reservedNames, true)) {
                continue;
            }

            if (str_ends_with($name, '_hide')) {
                continue;
            }

            // A category sheet must have a paired _hide sheet.
            if (in_array($name.'_hide', $sheetNames, true)) {
                $categorySheets[] = $name;
            }
        }

        if ($categorySheets === []) {
            throw new RowImportException(
                'ไม่พบชีทหมวดหมู่สินค้าในไฟล์เทมเพลต — กรุณาดาวน์โหลดเทมเพลตใหม่จาก Lazada'
            );
        }

        if (count($categorySheets) > 1) {
            $names = implode(', ', $categorySheets);

            throw new RowImportException(
                "พบหลายหมวดหมู่สินค้า ({$names}) ในไฟล์เทมเพลต — กรุณาใช้เทมเพลตที่มีเพียงหมวดหมู่เดียวเท่านั้น"
            );
        }

        return $categorySheets[0];
    }

    // ── TemplateFillImporter contract ─────────────────────────────────────

    public function targetSheet(): string
    {
        return $this->categorySheet;
    }

    /**
     * Discover the category sheet name from the workbook and cache it. This
     * is the production entry point — RunTemplateFillJob calls this once
     * before filling begins, replacing the old test-only setTargetSheet() /
     * IoC-instance-binding approach.
     */
    public function resolveTargetSheet(string $xlsxPath): string
    {
        $this->categorySheet = self::detectFromWorkbook($xlsxPath);

        return $this->categorySheet;
    }

    /**
     * Machine keys live in the paired hidden "_hide" sheet, not the visible
     * category sheet whose row 1 holds Thai human-readable labels.
     */
    public function keySheet(string $targetSheet): string
    {
        return $targetSheet.'_hide';
    }

    /**
     * Physical row 3 of the _hide sheet holds machine keys (rows 1–2 are
     * structural empty rows in the real Lazada template).
     */
    public function keyRow(): int
    {
        return 3;
    }

    public function dataStartRow(): int
    {
        return self::DATA_START_ROW;
    }

    /**
     * Map one Variant to its Lazada template column values.
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
        if ($product === null || empty($product->name)) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: product name is required for Lazada — fill it before filling the template."
            );
        }

        // Required: description.
        if (empty($product->description)) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: product description is required for Lazada — fill it before filling the template."
            );
        }

        // Required: at least one Product Image (Lazada REQUIRES a main image URL).
        $images = $product->images->sortBy('sort_order')->values();

        if ($images->isEmpty()) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: product has no images — Lazada requires at least one image URL. Add an image before filling the template."
            );
        }

        // Required: list price.
        if ($variant->list_price === null) {
            throw new RowImportException(
                "Variant [{$variant->master_sku}]: List Price is not set."
            );
        }

        // ── Build column-value map ───────────────────────────────────────

        $groupNo = $this->getOrAssignGroupNumber($product->id);

        /** @var array<string, string|int|float> $row */
        $row = [
            self::COL_GROUP_NO => $groupNo,
            self::COL_TITLE_TH => $product->name,
            self::COL_DESCRIPTION => $product->description,
            self::COL_SELLER_SKU => $variant->master_sku,
            self::COL_PRICE => $variant->list_price->toBaht(),
            self::COL_QUANTITY => max(0, $variant->availableAt($location)),
        ];

        // English title — skip if null/empty.
        if (! empty($product->english_name)) {
            $row[self::COL_TITLE_EN] = $product->english_name;
        }

        // Brand via catProperty.p-20000 — only if product has brand set.
        // columnIndex() returns null if the template's category doesn't have this
        // property key; writeCell is simply skipped in that case (ADR 0019 — we
        // never guess a different property key).
        if (! empty($product->brand)) {
            $row[self::COL_BRAND] = $product->brand;
        }

        // Main images (product-level): mainImage.0 .. mainImage.7.
        foreach ($images->take(self::MAIN_IMAGE_COUNT) as $i => $image) {
            $row['mainImage.'.$i] = $image->url;
        }

        // marketImages.1:1 — primary image URL (square crop for search card).
        // $images is guaranteed non-empty: we threw RowImportException above if it was empty.
        $row[self::COL_MARKET_IMAGE] = $images->first()->url;

        // SKU-level images — variant-scoped if any, else fall back to product-level.
        $skuImages = $product->images
            ->filter(static fn ($img) => $img->variant_id === $variant->id)
            ->sortBy('sort_order')
            ->values();

        if ($skuImages->isEmpty()) {
            $skuImages = $images;
        }

        foreach ($skuImages->take(self::SKU_IMAGE_COUNT) as $i => $image) {
            $row['sku.images.'.$i] = $image->url;
        }

        // Weight: grams → kg.
        if ($variant->package_weight_g !== null) {
            $row[self::COL_WEIGHT] = $variant->package_weight_g / 1000;
        }

        // Dimensions: millimetres → cm.
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

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Assign and return the 1-based group number for the given product.
     * All Variants of the same Product receive the same group number so that
     * Lazada's batch importer groups them into one multi-variant listing.
     */
    private function getOrAssignGroupNumber(int $productId): int
    {
        if (! isset($this->groupNumberMap[$productId])) {
            $this->groupNumberMap[$productId] = $this->nextGroupNumber;
            $this->nextGroupNumber++;
        }

        return $this->groupNumberMap[$productId];
    }
}
