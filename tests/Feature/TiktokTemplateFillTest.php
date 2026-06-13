<?php

/**
 * Tests for the TikTok Channel Upload Template fill engine (Issue #59,
 * ADR 0019 Phase 9 B).
 *
 * Covers:
 *  - WorkbookSurgeon byte-identity (hidden Brand sheet + non-target entries
 *    unchanged after fill)
 *  - Token row (row 2) preserved byte-perfect
 *  - Owned columns filled correctly for a 2-variant Product:
 *    product_name, product_description, brand="ไม่มีแบรนด์" (null) / blank
 *    (set), property_name_1/value_1 (multi-variant), seller_sku, baht price,
 *    clamped stock (0 when negative), weight in g (direct), mm → cm, image URLs
 *  - Foreign columns (category, size_chart, product_property/*) untouched
 *  - Image-less Variant NOT held — image cells left empty
 *  - Missing description = held fail-loud while good rows still fill
 *  - ListingVariant draft created / existing listed NOT downgraded / idempotent
 *  - Cross-tenant isolation (variant_ids from another tenant are invisible)
 *
 * NOTE: Coverage page bulk-action wiring and ImportJobResource download tests
 * are NOT included here — those test shared Filament infrastructure already
 * covered by ShopeeTemplateFillTest. They require the Platform enum hook to be
 * wired by the orchestrator before they can run.
 *
 * All helpers are prefixed `tiktok` to avoid collisions with other test files
 * loaded in the same Pest process.
 */

use App\Actions\Catalog\CreateProduct;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Enums\StockAction;
use App\Imports\ChannelTemplate\TiktokTemplateFiller;
use App\Jobs\RunTemplateFillJob;
use App\Models\ImportJob;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Support\Xlsx\WorkbookSurgeon;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

// ─────────────────────────────────────────────────────────────────────────────
// Test setup
// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('TK-A');
    app(TenantContext::class)->set($tenant);
    Storage::fake('local');
    actingAs(User::factory()->create());
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test helpers (all prefixed `tiktok` — never redefine helpers from other files)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a minimal but valid OOXML xlsx that mirrors the TikTok batch-upload
 * template structure (verified against
 * `ref doc/tiktok/batch upload product tiktok.xlsx`):
 *
 *   Sheet 1: "Template" (target sheet)
 *     Row 1: machine-key headers (plain keys, no |x|y suffixes)
 *     Row 2: token row (V5.0.2 | create_product | metric | category_v2 | …)
 *             — PRESERVED by WorkbookSurgeon
 *     Rows 3–5: preamble (Thai labels / required / instructions)
 *     Row 6+:   data rows (blank in template)
 *
 *   Sheet 2: "Brand" (hidden, for byte-identity test)
 *
 * Returns an absolute path to the temp xlsx.
 */
function tiktokBuildTemplateXlsx(): string
{
    $path = sys_get_temp_dir().'/tiktok-template-test-'.uniqid().'.xlsx';

    // Row 1: machine-key headers (plain — verified against real ref doc).
    $keyHeaders = [
        'category',
        'brand',
        'product_name',
        'product_description',
        'main_image',
        'image_2',
        'image_3',
        'image_4',
        'image_5',
        'image_6',
        'image_7',
        'image_8',
        'image_9',
        'property_name_1',
        'property_value_1',
        'property_1_image',
        'property_name_2',
        'property_value_2',
        'parcel_weight',
        'parcel_length',
        'parcel_width',
        'parcel_height',
        'delivery',
        'price',
        'deal_price',
        'pre_order_time',
        'quantity',
        'seller_sku',
        'size_chart',
        'cod',
        'product_property/100107',
    ];

    // Token row values (Row 2) — exact format from the real TikTok template.
    $tokenValues = [
        'V5.0.2',
        'create_product',
        'metric',
        'category_v2',
        '',    // E2 intentionally blank in the real template
        'TESTMD5PLACEHOLDERFORUNITTESTING',
        'normal_file',
        'TikTok Shop (0)',
    ];

    // Thai labels for row 3 (a representative subset).
    $thaiLabels = [
        'หมวดหมู่',
        'แบรนด์',
        'ชื่อสินค้า',
    ];

    // All shared strings = key headers + non-empty token values + Thai labels.
    $nonEmptyTokenValues = array_filter($tokenValues, static fn (string $v): bool => $v !== '');
    $allStrings = array_values(array_unique(
        array_merge($keyHeaders, array_values($nonEmptyTokenValues), $thaiLabels)
    ));

    $ssMap = array_flip($allStrings); // string → index

    // Build shared-strings XML.
    $siXml = '';
    foreach ($allStrings as $s) {
        $escaped = htmlspecialchars($s, ENT_XML1, 'UTF-8');
        $siXml .= "<si><t xml:space=\"preserve\">{$escaped}</t></si>";
    }

    $ssCount = count($allStrings);
    $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        ." count=\"{$ssCount}\" uniqueCount=\"{$ssCount}\">"
        .$siXml
        .'</sst>';

    // Helper: shared-string cell.
    $ssCell = static function (string $ref, string $value) use ($ssMap): string {
        $idx = $ssMap[$value] ?? 0;

        return "<c r=\"{$ref}\" t=\"s\"><v>{$idx}</v></c>";
    };

    // Row 1: machine-key headers.
    $row1Cells = '';
    foreach ($keyHeaders as $i => $key) {
        $colLetter = WorkbookSurgeon::indexToLetter($i + 1);
        $row1Cells .= $ssCell("{$colLetter}1", $key);
    }

    // Row 2: token row (shared-string cells; blank cols are skipped).
    $row2Cells = '';
    foreach ($tokenValues as $i => $val) {
        if ($val === '') {
            continue; // empty cols omitted, matching real template behaviour
        }
        $colLetter = WorkbookSurgeon::indexToLetter($i + 1);
        $row2Cells .= $ssCell("{$colLetter}2", $val);
    }

    // Row 3: a few Thai label cells.
    $row3Cells = $ssCell('A3', 'หมวดหมู่')
        .$ssCell('B3', 'แบรนด์')
        .$ssCell('C3', 'ชื่อสินค้า');

    // Target sheet: "Template" — data starts at row 6.
    $targetSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1:AD5"/>'
        .'<sheetData>'
        ."<row r=\"1\">{$row1Cells}</row>"
        ."<row r=\"2\">{$row2Cells}</row>"
        ."<row r=\"3\">{$row3Cells}</row>"
        .'<row r="4"></row>'
        .'<row r="5"></row>'
        .'</sheetData>'
        .'</worksheet>';

    // Hidden "Brand" sheet — sentinel value for byte-identity test.
    $brandSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>brand-sheet-sentinel-tiktok</t></is></c></row>'
        .'</sheetData>'
        .'</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'
        .'<sheet name="Template" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Brand" sheetId="2" r:id="rId2" state="hidden"/>'
        .'</sheets>'
        .'</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="1"><font><sz val="11"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
        .'<fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        .'</styleSheet>';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $targetSheetXml);
    $zip->addFromString('xl/worksheets/sheet2.xml', $brandSheetXml);
    $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    return $path;
}

/**
 * Extract all zip entries as path → raw content map.
 *
 * @return array<string, string>
 */
function tiktokExtractZipEntries(string $zipPath): array
{
    $z = new ZipArchive;
    $z->open($zipPath);
    $entries = [];

    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $content = $z->getFromIndex($i);
        if ($content === false) {
            throw new RuntimeException("Could not read entry {$i} from zip.");
        }
        $entries[$name] = $content;
    }

    $z->close();

    return $entries;
}

/**
 * Read a filled cell value in the "Template" sheet (sheet1.xml) by column
 * key prefix and row index.
 */
function tiktokReadFilledCell(string $filledPath, string $colKeyPrefix, int $rowIndex): ?string
{
    $surgeon = new WorkbookSurgeon($filledPath);
    $colIdx = $surgeon->columnIndex('Template', $colKeyPrefix);

    if ($colIdx === null) {
        return null;
    }

    $z = new ZipArchive;
    $z->open($filledPath);
    // Template is always sheet1.xml in the test fixture.
    $xml = $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();

    if ($xml === false) {
        throw new RuntimeException('Could not read xl/worksheets/sheet1.xml from xlsx.');
    }

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $ref = WorkbookSurgeon::indexToLetter($colIdx).$rowIndex;

    foreach ($dom->getElementsByTagNameNS($ns, 'c') as $c) {
        if ($c->getAttribute('r') === $ref) {
            $type = $c->getAttribute('t');

            if ($type === 'inlineStr') {
                $tNodes = $c->getElementsByTagNameNS($ns, 't');
                $tNode = $tNodes->item(0);

                return $tNode instanceof DOMNode ? $tNode->textContent : '';
            }

            $vNodes = $c->getElementsByTagNameNS($ns, 'v');
            $vNode = $vNodes->item(0);

            return $vNode instanceof DOMNode ? $vNode->textContent : null;
        }
    }

    return null;
}

/**
 * Create a TikTok marketplace Shop with the default Location.
 */
function tiktokFillShop(string $name = 'tiktok-fill'): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle($name, Platform::Tiktok, $location);
}

/**
 * Create a Product with Variants + stock at the default Location.
 *
 * @param  list<array{master_sku: string, list_price: Money, name?: string|null, barcode?: string|null, package_weight_g?: int|null, package_width_mm?: int|null, package_length_mm?: int|null, package_height_mm?: int|null}>  $variants
 */
function tiktokFillProduct(string $name, array $variants, int $onHand = 5): Product
{
    $product = app(CreateProduct::class)->handle($name, $variants);
    $location = Location::query()->where('is_default', true)->firstOrFail();

    foreach ($product->variants as $variant) {
        app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, $onHand);
    }

    return $product->load(['variants', 'images']);
}

/**
 * Store a blank TikTok template + create an ImportJob for RunTemplateFillJob.
 *
 * @param  int[]  $variantIds
 */
function tiktokMakeTemplateFillJob(Shop $shop, array $variantIds, string $diskKey = 'imports/1/tk.xlsx'): ImportJob
{
    $templatePath = tiktokBuildTemplateXlsx();
    $templateContents = file_get_contents($templatePath);
    if ($templateContents === false) {
        throw new RuntimeException("Cannot read template: {$templatePath}");
    }
    Storage::disk('local')->put($diskKey, $templateContents);

    return ImportJob::query()->create([
        'importer' => TiktokTemplateFiller::class,
        'original_filename' => 'tiktok-template.xlsx',
        'stored_path' => $diskKey,
        'status' => ImportJobStatus::Pending,
        'context' => ['shop_id' => $shop->id, 'variant_ids' => $variantIds],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// WorkbookSurgeon — byte-identity invariant
// ─────────────────────────────────────────────────────────────────────────────

it('WorkbookSurgeon (TikTok): non-target sheet entries (Brand) are content-identical after fill', function () {
    $templatePath = tiktokBuildTemplateXlsx();
    $filledPath = sys_get_temp_dir().'/tiktok-filled-'.uniqid().'.xlsx';

    $surgeon = new WorkbookSurgeon($templatePath);
    $surgeon->writeCell('Template', 6, 3, 'สินค้าทดสอบ');
    $surgeon->save($filledPath);

    $srcEntries = tiktokExtractZipEntries($templatePath);
    $dstEntries = tiktokExtractZipEntries($filledPath);

    expect(array_keys($srcEntries))->each->toBeIn(array_keys($dstEntries));

    foreach ($srcEntries as $name => $srcContent) {
        if ($name === 'xl/worksheets/sheet1.xml') {
            continue; // target sheet — expected to differ
        }

        expect($dstEntries[$name])->toBe(
            $srcContent,
            "Entry '{$name}' must be content-identical after fill."
        );
    }
});

it('WorkbookSurgeon (TikTok): token row (row 2) cells are preserved after writing row 6', function () {
    $templatePath = tiktokBuildTemplateXlsx();
    $filledPath = sys_get_temp_dir().'/tiktok-filled-'.uniqid().'.xlsx';

    $surgeon = new WorkbookSurgeon($templatePath);
    $surgeon->writeCell('Template', 6, 3, 'ชื่อสินค้า TikTok');
    $surgeon->save($filledPath);

    $z = new ZipArchive;
    $z->open($filledPath);
    $xml = $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();

    if ($xml === false) {
        throw new RuntimeException('Could not read xl/worksheets/sheet1.xml from filled xlsx.');
    }

    // Row 2 token cells must still be present.
    expect($xml)->toContain('r="A2"')
        ->and($xml)->toContain('r="1"')   // header row still present
        ->and($xml)->toContain('r="6"');  // data row written at row 6

    // The token cell A2 should NOT have been rewritten as inlineStr.
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    foreach ($dom->getElementsByTagNameNS($ns, 'c') as $c) {
        if ($c->getAttribute('r') === 'A2') {
            // Token row cell must still be shared-string type.
            expect($c->getAttribute('t'))->toBe('s');
            break;
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// RunTemplateFillJob — column fill correctness
// ─────────────────────────────────────────────────────────────────────────────

it('fills all owned TikTok columns correctly for a 2-variant product', function () {
    $shop = tiktokFillShop();

    // 12550 satang → "125.50" baht written to price.
    $product = tiktokFillProduct('เสื้อ TikTok', [
        [
            'master_sku' => 'TK-RED-M',
            'name' => 'แดง / M',
            'list_price' => Money::fromSatang(12550),
            'package_weight_g' => 300,
            'package_length_mm' => 200,
            'package_width_mm' => 150,
            'package_height_mm' => 40,
        ],
        [
            'master_sku' => 'TK-RED-L',
            'name' => 'แดง / L',
            'list_price' => Money::fromSatang(12550),
        ],
    ], onHand: 7);

    $product->update(['description' => 'คำอธิบายสินค้า TikTok']);
    // brand = null → should write "ไม่มีแบรนด์"
    $product->images()->create(['path' => 'img/main.jpg', 'sort_order' => 1]);
    $product->images()->create(['path' => 'img/second.jpg', 'sort_order' => 2]);
    $product->load('images');

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = tiktokMakeTemplateFillJob($shop, $variantIds);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    expect($importJob->status)->toBe(ImportJobStatus::Completed)
        ->and($importJob->error_rows)->toBe(0);

    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    expect(Storage::disk('local')->exists($resultPath))->toBeTrue();

    $fullPath = Storage::disk('local')->path($resultPath);

    // ── Row 6: first variant (sorted by id) ──────────────────────────────

    expect(tiktokReadFilledCell($fullPath, 'product_name', 6))->toBe('เสื้อ TikTok')
        ->and(tiktokReadFilledCell($fullPath, 'product_description', 6))->toBe('คำอธิบายสินค้า TikTok')
        ->and(tiktokReadFilledCell($fullPath, 'seller_sku', 6))->toBe('TK-RED-M')
        ->and(tiktokReadFilledCell($fullPath, 'price', 6))->toBe('125.50')
        ->and(tiktokReadFilledCell($fullPath, 'quantity', 6))->toBe('7')
        ->and(tiktokReadFilledCell($fullPath, 'parcel_weight', 6))->toBe('300')    // 300g → 300g (direct)
        ->and(tiktokReadFilledCell($fullPath, 'parcel_length', 6))->toBe('20')     // 200mm → 20cm
        ->and(tiktokReadFilledCell($fullPath, 'parcel_width', 6))->toBe('15')      // 150mm → 15cm
        ->and(tiktokReadFilledCell($fullPath, 'parcel_height', 6))->toBe('4');     // 40mm  → 4cm

    // Brand = "ไม่มีแบรนด์" (Product.brand is null).
    expect(tiktokReadFilledCell($fullPath, 'brand', 6))->toBe('ไม่มีแบรนด์');

    // Multi-variant: property_name_1 + property_value_1.
    expect(tiktokReadFilledCell($fullPath, 'property_name_1', 6))->toBe('ตัวเลือก')
        ->and(tiktokReadFilledCell($fullPath, 'property_value_1', 6))->toBe('แดง / M');

    // Images.
    expect(tiktokReadFilledCell($fullPath, 'main_image', 6))->toContain('main.jpg')
        ->and(tiktokReadFilledCell($fullPath, 'image_2', 6))->toContain('second.jpg');

    // ── Row 7: second variant ─────────────────────────────────────────────

    expect(tiktokReadFilledCell($fullPath, 'seller_sku', 7))->toBe('TK-RED-L')
        ->and(tiktokReadFilledCell($fullPath, 'property_value_1', 7))->toBe('แดง / L')
        ->and(tiktokReadFilledCell($fullPath, 'price', 7))->toBe('125.50');
});

it('leaves category and other non-owned columns untouched (absent) after fill', function () {
    $shop = tiktokFillShop();
    $product = tiktokFillProduct('สินค้า TK-X', [
        ['master_sku' => 'TK-X-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-X']);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = tiktokMakeTemplateFillJob($shop, $variantIds);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // category is not owned — must be absent (null).
    expect(tiktokReadFilledCell($fullPath, 'category', 6))->toBeNull();

    // size_chart is not owned — must be absent.
    expect(tiktokReadFilledCell($fullPath, 'size_chart', 6))->toBeNull();

    // product_property/* is not owned — must be absent.
    expect(tiktokReadFilledCell($fullPath, 'product_property/100107', 6))->toBeNull();
});

it('brand column is blank when Product.brand is set', function () {
    $shop = tiktokFillShop();
    $product = tiktokFillProduct('สินค้า TK-Brand', [
        ['master_sku' => 'TK-BRAND-1', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);
    $product->update([
        'description' => 'คำอธิบาย TK-Brand',
        'brand' => 'Nike',  // seller HAS a brand
    ]);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = tiktokMakeTemplateFillJob($shop, $variantIds, 'imports/1/tk-brand.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Brand cell must be absent — we never write the platform token for a set brand.
    expect(tiktokReadFilledCell($fullPath, 'brand', 6))->toBeNull();
});

it('stock clamped to 0 when Available is negative', function () {
    $shop = tiktokFillShop();
    $location = Location::query()->where('is_default', true)->firstOrFail();

    $product = app(CreateProduct::class)->handle('สินค้า TK-Neg', [
        ['master_sku' => 'TK-NEG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-Neg']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');

    // Push on-hand to 1, then SHIP 5 (oversell) → Available goes negative.
    app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 1);
    // POS-style SHIP: reservedReleased = 0 (param 7)
    app(AppendStockMovement::class)->handle($variant, $location, StockAction::Ship, 5, null, null, 0);

    $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id], 'imports/1/tk-neg.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Negative available → clamped to 0.
    expect(tiktokReadFilledCell($fullPath, 'quantity', 6))->toBe('0');
});

// ─────────────────────────────────────────────────────────────────────────────
// ListingVariant upsert logic
// ─────────────────────────────────────────────────────────────────────────────

it('creates a draft ListingVariant (platform_sku = master_sku) for each filled TikTok Variant', function () {
    $shop = tiktokFillShop();
    $product = tiktokFillProduct('สินค้า TK-LV', [
        ['master_sku' => 'TK-LV-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-LV']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $lv = ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->firstOrFail();

    expect($lv->listing_status)->toBe(ListingStatus::Draft)
        ->and($lv->platform_sku)->toBe('TK-LV-1');
});

it('never downgrades an existing listed ListingVariant to draft on re-fill', function () {
    $shop = tiktokFillShop();
    $product = tiktokFillProduct('สินค้า TK-NDG', [
        ['master_sku' => 'TK-NDG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-NDG']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');

    // Pre-seed a `listed` ListingVariant.
    $listing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $product->id,
    ]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'TK-NDG-1',
        'listing_status' => ListingStatus::Listed,
    ]);

    $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->firstOrFail()
        ->listing_status
    )->toBe(ListingStatus::Listed);
});

it('re-fill is idempotent — running three times creates only one TikTok ListingVariant', function () {
    $shop = tiktokFillShop();
    $product = tiktokFillProduct('สินค้า TK-IDEM', [
        ['master_sku' => 'TK-IDEM-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-IDEM']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');

    foreach (range(1, 3) as $run) {
        $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id], "imports/1/tk-idem-{$run}.xlsx");
        (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();
    }

    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->count()
    )->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fail-loud held rows (ADR 0005)
// ─────────────────────────────────────────────────────────────────────────────

it('holds TikTok Variants with no description fail-loud while good Variants still fill', function () {
    $shop = tiktokFillShop();

    $goodProduct = tiktokFillProduct('สินค้า TK-GOOD', [
        ['master_sku' => 'TK-GOOD-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $goodProduct->update(['description' => 'คำอธิบาย good']);

    $badProduct = tiktokFillProduct('สินค้า TK-BAD', [
        ['master_sku' => 'TK-BAD-1', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);
    // badProduct intentionally has no description.

    $goodVariant = $goodProduct->variants->first() ?? throw new RuntimeException('Expected a variant in goodProduct.');
    $badVariant = $badProduct->variants->first() ?? throw new RuntimeException('Expected a variant in badProduct.');

    $importJob = tiktokMakeTemplateFillJob($shop, [$badVariant->id, $goodVariant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain('TK-BAD-1');

    // Good Variant got a draft ListingVariant + result file.
    $contextFl = $importJob->context;
    expect(ListingVariant::query()->where('shop_id', $shop->id)->where('variant_id', $goodVariant->id)->exists())->toBeTrue()
        ->and(is_array($contextFl) ? ($contextFl['result_path'] ?? null) : null)->not->toBeNull();

    // Bad Variant was held — no ListingVariant.
    expect(ListingVariant::query()->where('shop_id', $shop->id)->where('variant_id', $badVariant->id)->exists())->toBeFalse();
});

it('TikTok: image-less Variant is NOT held — image cells simply left empty', function () {
    $shop = tiktokFillShop();
    $product = tiktokFillProduct('สินค้า TK-NoImg', [
        ['master_sku' => 'TK-NOIMG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-NoImg']);
    // No images added — intentional.

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    // Not a held row — should complete OK.
    expect($importJob->status)->toBe(ImportJobStatus::Completed)
        ->and($importJob->error_rows)->toBe(0);

    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Image cells must be absent — not written.
    expect(tiktokReadFilledCell($fullPath, 'main_image', 6))->toBeNull()
        ->and(tiktokReadFilledCell($fullPath, 'image_2', 6))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cross-tenant isolation
// ─────────────────────────────────────────────────────────────────────────────

it('TikTok: cross-tenant variant_ids are invisible — reported as not-found errors', function () {
    $shopA = tiktokFillShop('tiktok-A');

    // Tenant B: creates its own Variant.
    $tenantB = app(CreateTenant::class)->handle('TK-B');
    app(TenantContext::class)->set($tenantB);
    $productB = app(CreateProduct::class)->handle('สินค้า TK-B', [
        ['master_sku' => 'TK-B-SKU-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $variantB = $productB->variants->first() ?? throw new RuntimeException('Expected a variant in productB.');
    app(TenantContext::class)->forget();

    // Switch back to Tenant A.
    $tenantA = Tenant::query()->where('name', 'TK-A')->firstOrFail();
    app(TenantContext::class)->set($tenantA);
    Storage::fake('local');
    actingAs(User::factory()->create());

    $templateContents = file_get_contents(tiktokBuildTemplateXlsx());
    if ($templateContents === false) {
        throw new RuntimeException('Cannot read template.');
    }
    Storage::disk('local')->put("imports/{$tenantA->id}/tk.xlsx", $templateContents);

    $importJob = ImportJob::query()->create([
        'importer' => TiktokTemplateFiller::class,
        'original_filename' => 'tk.xlsx',
        'stored_path' => "imports/{$tenantA->id}/tk.xlsx",
        'status' => ImportJobStatus::Pending,
        'context' => ['shop_id' => $shopA->id, 'variant_ids' => [$variantB->id]],
    ]);

    (new RunTemplateFillJob($importJob->id, $tenantA->id))->handle();

    $importJob->refresh();

    // Tenant B's Variant invisible under Tenant A's RLS → "not found" error.
    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain((string) $variantB->id);

    expect(ListingVariant::query()->where('shop_id', $shopA->id)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Deal Price / deal_price column (Issue #75, ADR 0021)
// ─────────────────────────────────────────────────────────────────────────────

it('TikTok: fills deal_price when ListingVariant has a non-null deal_price', function () {
    $shop = tiktokFillShop('tiktok-deal-fill');
    $product = tiktokFillProduct('สินค้า TK-Deal', [
        ['master_sku' => 'DEAL-TK-1', 'name' => null, 'list_price' => Money::fromSatang(20000)],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-Deal']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');

    // Pre-seed a ListingVariant with deal_price (simulates an active Promotion Line).
    $listing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $product->id,
    ]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'DEAL-TK-1',
        'deal_price' => Money::fromSatang(15000),  // 150.00 baht deal price
        'listing_status' => ListingStatus::Draft,
    ]);

    $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id], 'imports/1/deal-tk.xlsx');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Deal Price column filled with the deal price in baht.
    expect(tiktokReadFilledCell($fullPath, 'deal_price', 6))->toBe('150.00');

    // List price column still filled correctly.
    expect(tiktokReadFilledCell($fullPath, 'price', 6))->toBe('200.00');
});

it('TikTok: deal_price column is empty when ListingVariant.deal_price is null (no promotion)', function () {
    $shop = tiktokFillShop('tiktok-no-deal');
    $product = tiktokFillProduct('สินค้า TK-NoDeal', [
        ['master_sku' => 'NODEAL-TK-1', 'name' => null, 'list_price' => Money::fromSatang(18000)],
    ]);
    $product->update(['description' => 'คำอธิบาย TK-NoDeal']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    // No ListingVariant pre-created → deal_price effectively null.

    $importJob = tiktokMakeTemplateFillJob($shop, [$variant->id], 'imports/1/nodeal-tk.xlsx');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Deal Price column not written (null) — no active promotion.
    expect(tiktokReadFilledCell($fullPath, 'deal_price', 6))->toBeNull();

    // List price still filled.
    expect(tiktokReadFilledCell($fullPath, 'price', 6))->toBe('180.00');
});
