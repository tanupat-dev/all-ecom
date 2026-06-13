<?php

/**
 * Tests for the Shopee Channel Upload Template fill engine (Issue #57,
 * ADR 0019 Phase 9 B).
 *
 * Covers:
 *  - WorkbookSurgeon byte-identity (non-target entries unchanged after fill)
 *  - Owned columns filled correctly for a 2-variant Product
 *  - Foreign / category columns untouched
 *  - ListingVariant draft created; existing `listed` row NOT downgraded; idempotent
 *  - Variant problem rows held fail-loud; good rows still fill
 *  - Cross-tenant isolation (variant_ids from another tenant are invisible)
 *  - Coverage bulk action wiring (Livewire::test)
 *  - ImportJobResource download action: visible/hidden, streams file, permission-gated, cross-tenant
 */

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartTemplateFill;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Enums\StockAction;
use App\Filament\Pages\ListingCoverage;
use App\Filament\Resources\ImportJobs\Pages\ListImportJobs;
use App\Imports\ChannelTemplate\ShopeeTemplateFiller;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// ─────────────────────────────────────────────────────────────────────────────
// Test setup
// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
    Storage::fake('local');
    actingAs(User::factory()->create());
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a minimal but valid OOXML xlsx that mirrors the Shopee batch-upload
 * template structure (verified against `ref doc/shopee/batch upload product
 * shopee.xlsx`):
 *
 *   Sheet 1: "ข้อมูลอื่น" (dummy "other" sheet — for byte-identity test)
 *   Sheet 2: "แบบฟอร์มการลงสินค้า" (target sheet)
 *     Row 1: machine-key headers (37 columns, with |x|y suffixes)
 *     Row 2: token row (basic, md5, 0, shop_id) — PRESERVED by WorkbookSurgeon
 *     Rows 3–6: preamble
 *     Row 7+: data (blank in template)
 *
 * Returns an absolute path to the temp xlsx.
 */
function buildShopeeTemplateXlsx(): string
{
    $path = sys_get_temp_dir().'/shopee-template-test-'.uniqid().'.xlsx';

    // Machine-key headers (Row 1) — exact strings from the ref doc.
    $keyHeaders = [
        'ps_category|0|0',
        'ps_product_name|1|0',
        'ps_product_description|1|0',
        'ps_maximum_purchase_quantity|0|0',
        'ps_maximum_purchase_quantity_start_date|0|0',
        'ps_maximum_purchase_quantity_time_period|0|0',
        'ps_maximum_purchase_quantity_end_date|0|0',
        'ps_minimum_purchase_quantity|0|0',
        'ps_sku_parent_short|0|0',
        'et_title_variation_integration_no|0|0',
        'et_title_variation_1|0|0',
        'et_title_option_for_variation_1|0|0',
        'et_title_image_per_variation|0|3',
        'et_title_variation_2|0|0',
        'et_title_option_for_variation_2|0|0',
        'ps_price|1|1',
        'ps_stock|0|1',
        'ps_sku_short|0|0',
        'ps_new_size_chart|0|1',
        'et_title_size_chart|0|3',
        'ps_gtin_code|0|0',
        'ps_item_cover_image|0|3',
        'ps_item_image_1|0|3',
        'ps_item_image_2|0|3',
        'ps_item_image_3|0|3',
        'ps_item_image_4|0|3',
        'ps_item_image_5|0|3',
        'ps_item_image_6|0|3',
        'ps_item_image_7|0|3',
        'ps_item_image_8|0|3',
        'ps_weight|0|1',
        'ps_length|0|1',
        'ps_width|0|1',
        'ps_height|0|1',
        'channel_id.7000|0|0',
        'ps_product_pre_order_dts|0|1',
        'et_title_reason|0|0',
        'ราคาส่วนลด|0|0',
    ];

    // All shared strings: header keys + token row values + Thai labels.
    $allStrings = array_merge($keyHeaders, [
        'basic',
        '8dddd2f7d90a3b8728b28316f98263b6',
        '0',
        '217521245',
        'ประเภทสินค้า',
        'ชื่อสินค้า',
        'คำอธิบาย',
    ]);

    $ssMap = array_flip($allStrings);  // string → index

    // Build shared strings XML.
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

    // Helper: build a shared-string cell.
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

    // Row 2: token row (preserved by WorkbookSurgeon).
    $row2Cells = $ssCell('A2', 'basic')
        .$ssCell('B2', '8dddd2f7d90a3b8728b28316f98263b6')
        .$ssCell('C2', '0')
        .$ssCell('D2', '217521245');

    // Rows 3–6: minimal preamble.
    $row3Cells = $ssCell('A3', 'ประเภทสินค้า')
        .$ssCell('B3', 'ชื่อสินค้า')
        .$ssCell('C3', 'คำอธิบาย');

    // Target sheet: "แบบฟอร์มการลงสินค้า".
    $targetSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1:AK6"/>'
        .'<sheetData>'
        ."<row r=\"1\">{$row1Cells}</row>"
        ."<row r=\"2\">{$row2Cells}</row>"
        ."<row r=\"3\">{$row3Cells}</row>"
        .'<row r="4"></row>'
        .'<row r="5"></row>'
        .'<row r="6"></row>'
        .'</sheetData>'
        .'</worksheet>';

    // Dummy "other" sheet for byte-identity test.
    $otherSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>other-sheet-sentinel</t></is></c></row>'
        .'</sheetData>'
        .'</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'
        .'<sheet name="ข้อมูลอื่น" sheetId="1" r:id="rId1"/>'
        .'<sheet name="แบบฟอร์มการลงสินค้า" sheetId="2" r:id="rId2"/>'
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
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
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
    $zip->addFromString('xl/worksheets/sheet1.xml', $otherSheetXml);
    $zip->addFromString('xl/worksheets/sheet2.xml', $targetSheetXml);
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
function extractZipEntries(string $zipPath): array
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
 * Read a filled cell value by column key prefix + row index.
 */
function readFilledCell(string $filledPath, string $colKeyPrefix, int $rowIndex): ?string
{
    $surgeon = new WorkbookSurgeon($filledPath);
    $colIdx = $surgeon->columnIndex('แบบฟอร์มการลงสินค้า', $colKeyPrefix);

    if ($colIdx === null) {
        return null;
    }

    $z = new ZipArchive;
    $z->open($filledPath);
    $xml = $z->getFromName('xl/worksheets/sheet2.xml');
    $z->close();

    if ($xml === false) {
        throw new RuntimeException('Could not read xl/worksheets/sheet2.xml from xlsx.');
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
 * Create a Shopee marketplace Shop with the default Location.
 */
function fillShop(string $name = 'shopee-fill'): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle($name, Platform::Shopee, $location);
}

/**
 * Create a Product with Variants + stock at the default Location.
 *
 * @param  list<array{master_sku: string, list_price: Money, name?: string|null, barcode?: string|null, package_weight_g?: int|null, package_width_mm?: int|null, package_length_mm?: int|null, package_height_mm?: int|null}>  $variants
 */
function fillProduct(string $name, array $variants, int $onHand = 5): Product
{
    $product = app(CreateProduct::class)->handle($name, $variants);
    $location = Location::query()->where('is_default', true)->firstOrFail();

    foreach ($product->variants as $variant) {
        app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, $onHand);
    }

    return $product->load(['variants', 'images']);
}

/**
 * Store a blank template + create an ImportJob for RunTemplateFillJob.
 *
 * @param  int[]  $variantIds
 */
function makeTemplateFillJob(Shop $shop, array $variantIds, string $diskKey = 'imports/1/t.xlsx'): ImportJob
{
    $templatePath = buildShopeeTemplateXlsx();
    $templateContents = file_get_contents($templatePath);
    if ($templateContents === false) {
        throw new RuntimeException("Cannot read template: {$templatePath}");
    }
    Storage::disk('local')->put($diskKey, $templateContents);

    return ImportJob::query()->create([
        'importer' => ShopeeTemplateFiller::class,
        'original_filename' => 'template.xlsx',
        'stored_path' => $diskKey,
        'status' => ImportJobStatus::Pending,
        'context' => ['shop_id' => $shop->id, 'variant_ids' => $variantIds],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// WorkbookSurgeon — byte-identity invariant
// ─────────────────────────────────────────────────────────────────────────────

it('WorkbookSurgeon: non-target sheet entries are content-identical after fill', function () {
    $templatePath = buildShopeeTemplateXlsx();
    $filledPath = sys_get_temp_dir().'/shopee-filled-'.uniqid().'.xlsx';

    $surgeon = new WorkbookSurgeon($templatePath);
    $surgeon->writeCell('แบบฟอร์มการลงสินค้า', 7, 2, 'สินค้าทดสอบ');
    $surgeon->save($filledPath);

    $srcEntries = extractZipEntries($templatePath);
    $dstEntries = extractZipEntries($filledPath);

    expect(array_keys($srcEntries))->each->toBeIn(array_keys($dstEntries));

    foreach ($srcEntries as $name => $srcContent) {
        if ($name === 'xl/worksheets/sheet2.xml') {
            continue; // target sheet — expected to differ
        }

        expect($dstEntries[$name])->toBe(
            $srcContent,
            "Entry '{$name}' must be content-identical after fill."
        );
    }
});

it('WorkbookSurgeon: token row (row 2) cells are preserved after writing row 7', function () {
    $templatePath = buildShopeeTemplateXlsx();
    $filledPath = sys_get_temp_dir().'/shopee-filled-'.uniqid().'.xlsx';

    $surgeon = new WorkbookSurgeon($templatePath);
    $surgeon->writeCell('แบบฟอร์มการลงสินค้า', 7, 2, 'ชื่อสินค้า');
    $surgeon->save($filledPath);

    $z = new ZipArchive;
    $z->open($filledPath);
    $xml = $z->getFromName('xl/worksheets/sheet2.xml');
    $z->close();

    if ($xml === false) {
        throw new RuntimeException('Could not read xl/worksheets/sheet2.xml from filled xlsx.');
    }

    // Row 2 token cells still use shared-string type (t="s"), not inlineStr.
    expect($xml)->toContain('r="B2"')
        ->and($xml)->toContain('r="1"')   // header row still present
        ->and($xml)->toContain('r="7"');  // data row written

    // The token cell B2 should NOT have been rewritten as inlineStr.
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    foreach ($dom->getElementsByTagNameNS($ns, 'c') as $c) {
        if ($c->getAttribute('r') === 'B2') {
            // Token row cell must still be shared-string type.
            expect($c->getAttribute('t'))->toBe('s');
            break;
        }
    }
});

it('WorkbookSurgeon: indexToLetter and letterToIndex round-trip correctly', function () {
    foreach ([1 => 'A', 2 => 'B', 26 => 'Z', 27 => 'AA', 28 => 'AB', 703 => 'AAA'] as $idx => $letter) {
        expect(WorkbookSurgeon::indexToLetter($idx))->toBe($letter)
            ->and(WorkbookSurgeon::letterToIndex($letter))->toBe($idx);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// RunTemplateFillJob — column fill correctness
// ─────────────────────────────────────────────────────────────────────────────

it('fills all owned Shopee columns correctly for a 2-variant product', function () {
    $shop = fillShop();

    // 12550 satang → "125.50" baht written to ps_price.
    $product = fillProduct('เสื้อ Deblu', [
        [
            'master_sku' => 'DEBLU-RED-M',
            'name' => 'แดง / M',
            'list_price' => Money::fromSatang(12550),
            'package_weight_g' => 250,
            'package_length_mm' => 300,
            'package_width_mm' => 200,
            'package_height_mm' => 50,
        ],
        [
            'master_sku' => 'DEBLU-RED-L',
            'name' => 'แดง / L',
            'list_price' => Money::fromSatang(12550),
        ],
    ], onHand: 5);

    $product->update(['description' => 'คำอธิบายสินค้า Deblu']);
    $product->images()->create(['path' => 'img/cover.jpg', 'sort_order' => 1]);
    $product->images()->create(['path' => 'img/extra.jpg', 'sort_order' => 2]);
    $product->load('images');

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = makeTemplateFillJob($shop, $variantIds);

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
    expect($resultPath)->not->toBeNull();
    expect(Storage::disk('local')->exists($resultPath))->toBeTrue();

    $fullPath = Storage::disk('local')->path($resultPath);

    // ── Row 7: first variant (sorted by id) ──────────────────────────────

    expect(readFilledCell($fullPath, 'ps_product_name', 7))->toBe('เสื้อ Deblu')
        ->and(readFilledCell($fullPath, 'ps_product_description', 7))->toBe('คำอธิบายสินค้า Deblu')
        ->and(readFilledCell($fullPath, 'ps_sku_short', 7))->toBe('DEBLU-RED-M')
        ->and(readFilledCell($fullPath, 'ps_price', 7))->toBe('125.50')
        ->and(readFilledCell($fullPath, 'ps_stock', 7))->toBe('5')
        ->and(readFilledCell($fullPath, 'ps_weight', 7))->toBe('0.25')  // 250g → 0.25 kg
        ->and(readFilledCell($fullPath, 'ps_length', 7))->toBe('30')    // 300mm → 30 cm
        ->and(readFilledCell($fullPath, 'ps_width', 7))->toBe('20')     // 200mm → 20 cm
        ->and(readFilledCell($fullPath, 'ps_height', 7))->toBe('5');    // 50mm → 5 cm

    // Multi-variant: parent SKU + variation title + option.
    expect(readFilledCell($fullPath, 'ps_sku_parent_short', 7))->toBe('DEBLU-RED-M')
        ->and(readFilledCell($fullPath, 'et_title_variation_1', 7))->toBe('ตัวเลือก')
        ->and(readFilledCell($fullPath, 'et_title_option_for_variation_1', 7))->toBe('แดง / M');

    // Cover image and first extra image.
    expect(readFilledCell($fullPath, 'ps_item_cover_image', 7))->toContain('cover.jpg');
    expect(readFilledCell($fullPath, 'ps_item_image_1', 7))->toContain('extra.jpg');

    // ── Row 8: second variant ─────────────────────────────────────────────

    expect(readFilledCell($fullPath, 'ps_sku_short', 8))->toBe('DEBLU-RED-L')
        ->and(readFilledCell($fullPath, 'et_title_option_for_variation_1', 8))->toBe('แดง / L')
        ->and(readFilledCell($fullPath, 'ps_price', 8))->toBe('125.50');
});

it('leaves category and other non-owned columns untouched (absent) after fill', function () {
    $shop = fillShop();
    $product = fillProduct('สินค้า X', [
        ['master_sku' => 'X-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย X']);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = makeTemplateFillJob($shop, $variantIds);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // ps_category is not owned — must be absent (null).
    expect(readFilledCell($fullPath, 'ps_category', 7))->toBeNull();

    // ps_gtin_code is not owned — must be absent.
    expect(readFilledCell($fullPath, 'ps_gtin_code', 7))->toBeNull();
});

it('stock clamped to 0 when Available is negative', function () {
    $shop = fillShop();
    $location = Location::query()->where('is_default', true)->firstOrFail();

    $product = app(CreateProduct::class)->handle('สินค้า Neg', [
        ['master_sku' => 'NEG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');

    // Push on-hand to 1, then SHIP 5 (oversell) → Available goes negative.
    app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 1);
    // POS-style SHIP: reservedReleased = 0 (5th param is ?Model $ref, 7th is ?int $reservedReleased)
    app(AppendStockMovement::class)->handle($variant, $location, StockAction::Ship, 5, null, null, 0);

    $importJob = makeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Negative available → clamped to 0.
    expect(readFilledCell($fullPath, 'ps_stock', 7))->toBe('0');
});

// ─────────────────────────────────────────────────────────────────────────────
// ListingVariant upsert logic
// ─────────────────────────────────────────────────────────────────────────────

it('creates a draft ListingVariant (platform_sku = master_sku) for each filled Variant', function () {
    $shop = fillShop();
    $product = fillProduct('สินค้า LV', [
        ['master_sku' => 'LV-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    $importJob = makeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $lv = ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->firstOrFail();

    expect($lv->listing_status)->toBe(ListingStatus::Draft)
        ->and($lv->platform_sku)->toBe('LV-1');
});

it('never downgrades an existing listed ListingVariant to draft on re-fill', function () {
    $shop = fillShop();
    $product = fillProduct('สินค้า NDG', [
        ['master_sku' => 'NDG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');

    // Pre-seed a `listed` ListingVariant (ground truth from Platform import).
    $listing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $product->id,
    ]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'NDG-1',
        'listing_status' => ListingStatus::Listed,
    ]);

    $importJob = makeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->firstOrFail()
        ->listing_status
    )->toBe(ListingStatus::Listed);
});

it('re-fill is idempotent — running three times creates only one ListingVariant', function () {
    $shop = fillShop();
    $product = fillProduct('สินค้า IDEM', [
        ['master_sku' => 'IDEM-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');

    foreach (range(1, 3) as $run) {
        $importJob = makeTemplateFillJob($shop, [$variant->id], "imports/1/t-{$run}.xlsx");
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

it('holds Variants with no description fail-loud while good Variants still fill', function () {
    $shop = fillShop();

    $goodProduct = fillProduct('สินค้า GOOD', [
        ['master_sku' => 'GOOD-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $goodProduct->update(['description' => 'คำอธิบาย good']);

    $badProduct = fillProduct('สินค้า BAD', [
        ['master_sku' => 'BAD-1', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);
    // badProduct intentionally has no description.

    $goodVariant = $goodProduct->variants->first() ?? throw new RuntimeException('Expected at least one variant in goodProduct.');
    $badVariant = $badProduct->variants->first() ?? throw new RuntimeException('Expected at least one variant in badProduct.');

    $importJob = makeTemplateFillJob($shop, [$badVariant->id, $goodVariant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain('BAD-1');

    // Good variant got a draft ListingVariant + result file.
    $contextFl = $importJob->context;
    expect(ListingVariant::query()->where('shop_id', $shop->id)->where('variant_id', $goodVariant->id)->exists())->toBeTrue()
        ->and(is_array($contextFl) ? ($contextFl['result_path'] ?? null) : null)->not->toBeNull();

    // Bad variant was held — no ListingVariant.
    expect(ListingVariant::query()->where('shop_id', $shop->id)->where('variant_id', $badVariant->id)->exists())->toBeFalse();
});

it('image-less Variant is NOT held — image cells simply left empty', function () {
    $shop = fillShop();
    $product = fillProduct('สินค้า NoImg', [
        ['master_sku' => 'NOIMG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);
    // No images added — intentional.

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    $importJob = makeTemplateFillJob($shop, [$variant->id]);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    // Not a held row — should complete OK.
    expect($importJob->status)->toBe(ImportJobStatus::Completed)
        ->and($importJob->error_rows)->toBe(0);

    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);
    expect(readFilledCell($fullPath, 'ps_item_cover_image', 7))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cross-tenant isolation
// ─────────────────────────────────────────────────────────────────────────────

it('cross-tenant variant_ids are invisible — reported as not-found errors', function () {
    $shopA = fillShop('shopee-A');

    // Tenant B: creates its own variant.
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $locationB = Location::query()->where('is_default', true)->firstOrFail();
    $productB = app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'B-SKU-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $variantB = $productB->variants->first() ?? throw new RuntimeException('Expected at least one variant in productB.');
    app(TenantContext::class)->forget();

    // Switch back to Tenant A.
    $tenantA = Tenant::query()->where('name', 'A')->firstOrFail();
    app(TenantContext::class)->set($tenantA);
    Storage::fake('local');
    actingAs(User::factory()->create());

    $templatePath = buildShopeeTemplateXlsx();
    $templateContents = file_get_contents($templatePath);
    if ($templateContents === false) {
        throw new RuntimeException("Cannot read template: {$templatePath}");
    }
    Storage::disk('local')->put("imports/{$tenantA->id}/t.xlsx", $templateContents);

    $importJob = ImportJob::query()->create([
        'importer' => ShopeeTemplateFiller::class,
        'original_filename' => 't.xlsx',
        'stored_path' => "imports/{$tenantA->id}/t.xlsx",
        'status' => ImportJobStatus::Pending,
        'context' => ['shop_id' => $shopA->id, 'variant_ids' => [$variantB->id]],
    ]);

    (new RunTemplateFillJob($importJob->id, $tenantA->id))->handle();

    $importJob->refresh();

    // Tenant B's variant invisible under Tenant A's RLS → "not found" error.
    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain((string) $variantB->id);

    expect(ListingVariant::query()->where('shop_id', $shopA->id)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Coverage bulk action wiring
// ─────────────────────────────────────────────────────────────────────────────

it('fillChannelTemplate bulk action exists and is visible for listing.manage users', function () {
    // Use tenantWithUser from RbacTest (loaded when Pest discovers all tests).
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    // Need at least one Variant for the table to render.
    app(CreateProduct::class)
        ->handle('สินค้า BA-1', [['master_sku' => 'BA-1', 'list_price' => Money::fromBaht('100')]]);

    Livewire::test(ListingCoverage::class)
        ->assertTableBulkActionExists('fillChannelTemplate');
});

it('fillChannelTemplate bulk action is hidden for users without listing.manage', function () {
    // Create a fresh tenant with an Admin user (to seed roles + permissions).
    [$tenant] = tenantWithUser('Admin');

    // Give this test user listing.view but NOT listing.manage.
    $viewOnlyUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $viewOnlyUser->givePermissionTo('listing.view');
    actingAs($viewOnlyUser);

    app(CreateProduct::class)
        ->handle('สินค้า BA-CASHIER', [['master_sku' => 'BA-CASHIER', 'list_price' => Money::fromBaht('100')]]);

    Livewire::test(ListingCoverage::class)
        ->assertTableBulkActionHidden('fillChannelTemplate');
});

it('StartTemplateFill dispatches RunTemplateFillJob with correct ImportJob', function () {
    // Verify the pipeline wiring: StartTemplateFill creates an ImportJob
    // and dispatches RunTemplateFillJob to the queue.
    Queue::fake();

    $shop = fillShop('shopee-dispatch');
    $product = fillProduct('สินค้า Dispatch', [
        ['master_sku' => 'DISPATCH-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();

    $templatePath = buildShopeeTemplateXlsx();
    $file = new UploadedFile($templatePath, 'template.xlsx', null, null, true);

    $importJob = app(StartTemplateFill::class)->handle(
        $file,
        ShopeeTemplateFiller::class,
        ['shop_id' => $shop->id, 'variant_ids' => $variantIds],
    );

    Queue::assertPushed(RunTemplateFillJob::class, function (RunTemplateFillJob $job) use ($importJob) {
        return $job->importJobId === $importJob->id;
    });

    $jobContext = $importJob->context;
    if (! is_array($jobContext)) {
        throw new RuntimeException('Expected context to be an array.');
    }
    expect($importJob->importer)->toBe(ShopeeTemplateFiller::class)
        ->and($jobContext['shop_id'] ?? null)->toBe($shop->id)
        ->and($jobContext['variant_ids'] ?? null)->toBe($variantIds);
});

it('Platform::templateFillImporter returns ShopeeTemplateFiller for Shopee, null for others', function () {
    expect(Platform::Shopee->templateFillImporter())->toBe(ShopeeTemplateFiller::class)
        ->and(Platform::Pos->templateFillImporter())->toBeNull()
        ->and(Platform::Line->templateFillImporter())->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ImportJobResource — downloadFilledResult action (Issue #57 follow-up)
// ─────────────────────────────────────────────────────────────────────────────

it('downloadFilledResult action is visible for a completed fill job with result_path', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shop = fillShop('shopee-dl-vis');
    $product = fillProduct('สินค้า DL-VIS', [
        ['master_sku' => 'DL-VIS-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = makeTemplateFillJob($shop, $variantIds);
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();
    $importJob->refresh();

    $context = $importJob->context;
    expect(is_array($context) ? ($context['result_path'] ?? null) : null)->not->toBeNull();

    Livewire::test(ListImportJobs::class)
        ->assertTableActionVisible('downloadFilledResult', $importJob);
});

it('downloadFilledResult action is hidden for a pending job without result_path', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shop = fillShop('shopee-dl-hid');
    $product = fillProduct('สินค้า DL-HID', [
        ['master_sku' => 'DL-HID-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    // Job stays Pending — no result_path in context.
    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = makeTemplateFillJob($shop, $variantIds);

    $context = $importJob->context;
    expect(is_array($context) ? ($context['result_path'] ?? null) : null)->toBeNull();

    Livewire::test(ListImportJobs::class)
        ->assertTableActionHidden('downloadFilledResult', $importJob);
});

it('downloadFilledResult streams the filled xlsx with the correct shopee filename', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shop = fillShop('shopee-dl-stream');
    $product = fillProduct('สินค้า DL-STREAM', [
        ['master_sku' => 'DL-STREAM-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = makeTemplateFillJob($shop, $variantIds);
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();
    $importJob->refresh();

    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath) || $resultPath === '') {
        throw new RuntimeException('Expected a non-empty string result_path after fill.');
    }

    $expectedFilename = 'shopee-template-filled-'.$importJob->id.'.xlsx';
    $expectedContent = Storage::disk('local')->get($resultPath);

    Livewire::test(ListImportJobs::class)
        ->callTableAction('downloadFilledResult', $importJob)
        ->assertFileDownloaded($expectedFilename, $expectedContent);
});

it('downloadFilledResult is gated on listing.manage — Admin can, listing.view-only user cannot', function () {
    [$tenant, $admin] = tenantWithUser('Admin');

    $shop = fillShop('shopee-dl-rbac');
    $product = fillProduct('สินค้า DL-RBAC', [
        ['master_sku' => 'DL-RBAC-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = makeTemplateFillJob($shop, $variantIds);

    // Admin has listing.manage → allowed.
    expect($admin->can('downloadFilledResult', $importJob))->toBeTrue();

    // A user with only listing.view → denied (listing.manage is required).
    $viewOnly = User::factory()->create(['tenant_id' => $tenant->id]);
    $viewOnly->givePermissionTo('listing.view');
    expect($viewOnly->can('downloadFilledResult', $importJob))->toBeFalse();
    // viewAny also requires listing.manage, so a listing.view-only user is
    // blocked at the resource level too.
    expect($viewOnly->can('viewAny', ImportJob::class))->toBeFalse();
});

it('cross-tenant: another tenant\'s ImportJob is invisible in the ImportJobResource list', function () {
    // Tenant A: create and complete a fill job.
    [$tenantA, $adminA] = tenantWithUser('Admin');
    actingAs($adminA);

    $shopA = fillShop('shopee-ct-dl-a');
    $productA = fillProduct('สินค้า CT-DL-A', [
        ['master_sku' => 'CT-DL-A-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $productA->update(['description' => 'คำอธิบาย']);

    $variantIdsA = $productA->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJobA = makeTemplateFillJob($shopA, $variantIdsA, 'imports/ct-dl/a.xlsx');
    (new RunTemplateFillJob($importJobA->id, $tenantA->id))->handle();
    $importJobA->refresh();

    $contextA = $importJobA->context;
    expect(is_array($contextA) ? ($contextA['result_path'] ?? null) : null)->not->toBeNull();

    // Tenant B: completely separate admin.
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B-CT-DL');
    app(TenantContext::class)->set($tenantB);
    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $userB->assignRole('Admin');
    actingAs($userB);

    // Tenant B's list must NOT show Tenant A's job.
    Livewire::test(ListImportJobs::class)
        ->assertCanNotSeeTableRecords([$importJobA]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Deal Price / ราคาส่วนลด column (Issue #75, ADR 0021)
// ─────────────────────────────────────────────────────────────────────────────

it('Shopee: fills ราคาส่วนลด when ListingVariant has a non-null deal_price', function () {
    $shop = fillShop('shopee-deal-fill');
    $product = fillProduct('สินค้า Deal', [
        ['master_sku' => 'DEAL-S-1', 'name' => null, 'list_price' => Money::fromSatang(15000)],
    ]);
    $product->update(['description' => 'คำอธิบาย Deal']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');

    // Pre-seed a ListingVariant with deal_price (simulates an active Promotion Line).
    $listing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $product->id,
    ]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'DEAL-S-1',
        'deal_price' => Money::fromSatang(9000),  // 90.00 baht deal price
        'listing_status' => ListingStatus::Draft,
    ]);

    $importJob = makeTemplateFillJob($shop, [$variant->id], 'imports/1/deal-s.xlsx');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Discount column filled with the deal price in baht.
    expect(readFilledCell($fullPath, 'ราคาส่วนลด', 7))->toBe('90.00');

    // List price column still filled correctly.
    expect(readFilledCell($fullPath, 'ps_price', 7))->toBe('150.00');
});

it('Shopee: ราคาส่วนลด column is empty when ListingVariant.deal_price is null (no promotion)', function () {
    $shop = fillShop('shopee-no-deal');
    $product = fillProduct('สินค้า NoDeal', [
        ['master_sku' => 'NODEAL-S-1', 'name' => null, 'list_price' => Money::fromSatang(12000)],
    ]);
    $product->update(['description' => 'คำอธิบาย NoDeal']);

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected at least one variant.');
    // No ListingVariant pre-created → deal_price effectively null.

    $importJob = makeTemplateFillJob($shop, [$variant->id], 'imports/1/nodeal-s.xlsx');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;
    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path to be set after fill.');
    }
    $fullPath = Storage::disk('local')->path($resultPath);

    // Discount column not written (null) — no active promotion.
    expect(readFilledCell($fullPath, 'ราคาส่วนลด', 7))->toBeNull();

    // List price still filled.
    expect(readFilledCell($fullPath, 'ps_price', 7))->toBe('120.00');
});
