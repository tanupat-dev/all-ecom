<?php

/**
 * Tests for the Lazada Channel Upload Template fill engine (Issue #58,
 * ADR 0019 Phase 9 B).
 *
 * Covers:
 *  - WorkbookSurgeon byte-identity: non-target entries unchanged after fill
 *    (including _hide sheet + global_hide token entry)
 *  - Owned columns filled correctly for a 2-variant Product
 *    (TH/EN names, description, brand via p-20000, group no, SKU, baht price,
 *     clamped stock, kg/cm conversions, image URLs incl. marketImages.1:1)
 *  - Foreign / category columns untouched (catId, sku.shop_sku, etc.)
 *  - Image-less Variant = held fail-loud; good rows still fill
 *  - Multi-category workbook = fail-loud Thai message
 *  - ListingVariant draft created / listed not downgraded / idempotent
 *  - Cross-tenant isolation (Variant IDs from another tenant → not-found error)
 *
 * ── Fixture structure (real Lazada template shape) ────────────────────────
 * The fixture uses the REAL Lazada template layout:
 *   - Visible category sheet row 1: Thai placeholder labels (NOT machine keys)
 *   - _hide sheet row 3: machine keys as inlineStr (real structure)
 * RunTemplateFillJob drives the production path:
 *   resolveTargetSheet() → detectFromWorkbook() → dynamic sheet name
 *   keySheet() → "<category>_hide"
 *   keyRow()   → 3
 * No IoC pre-binding or setTargetSheet() hacks — exercised end-to-end.
 *
 * All test helpers are prefixed `lazada` to avoid global namespace collisions
 * with the Shopee test helpers (Pest loads all test files in one process).
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
use App\Imports\ChannelTemplate\LazadaTemplateFiller;
use App\Imports\RowImportException;
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

use function Pest\Laravel\actingAs;

// ─────────────────────────────────────────────────────────────────────────────
// Test setup
// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('LA');
    app(TenantContext::class)->set($tenant);
    Storage::fake('local');
    actingAs(User::factory()->create());
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─────────────────────────────────────────────────────────────────────────────
// Machine key columns (from ref doc/lazada/batch upload product lazada.xlsx)
// Row 3 of the _hide sheet = same column positions as the visible sheet.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The full 65-column machine key list from the ref doc.
 * Used to build the _hide sheet's physical row 3. The visible sheet row 1
 * carries Thai placeholder labels (real Lazada template structure).
 *
 * @return list<string>
 */
function lazadaMachineKeys(): array
{
    return [
        'productNoForBatch',           // A  (col 1)
        'catId',                       // B
        'title.th_TH',                 // C
        'title.en_TH',                 // D
        'mainImage.0',                 // E
        'mainImage.1',                 // F
        'mainImage.2',                 // G
        'mainImage.3',                 // H
        'mainImage.4',                 // I
        'mainImage.5',                 // J
        'mainImage.6',                 // K
        'mainImage.7',                 // L
        'marketImages.1:1',            // M
        'originalLocalName',           // N
        'currencyCode',                // O
        'newVideo',                    // P
        'catProperty.p-20000',         // Q
        'catProperty.p-31051',         // R
        'catProperty.p-40385',         // S
        'catProperty.p-31011',         // T
        'catProperty.p-31032',         // U
        'catProperty.p-30905',         // V
        'catProperty.p-31008',         // W
        'catProperty.p-30945',         // X
        'catProperty.p-90117',         // Y
        'catProperty.p-30376',         // Z
        'catProperty.p-31022',         // AA
        'catProperty.p-30096',         // AB
        'catProperty.p-120380401',     // AC
        'description',                 // AD
        'enDescription',               // AE
        'packageContent',              // AF
        'warrantyPolicy',              // AG
        'warrantyPeriod',              // AH
        'warrantyType',                // AI
        'radioDangerousGoods',         // AJ
        'deliveryStandard',            // AK
        'saleProp.p-30097',            // AL
        'saleProp.p-30585',            // AM
        'sku.skuPreOrder.enable',      // AN
        'sku.skuPreOrder.shipDays',    // AO
        'sku.props',                   // AP
        'sku.shop_sku',                // AQ
        'sku.images.0',                // AR
        'sku.images.1',                // AS
        'sku.images.2',                // AT
        'sku.images.3',                // AU
        'sku.images.4',                // AV
        'sku.images.5',                // AW
        'sku.images.6',                // AX
        'sku.images.7',                // AY
        'sku.color_thumbnail',         // AZ
        'sku.package_weight',          // BA
        'sku.quantity',                // BB
        'sku.special_price.SpecialPrice', // BC
        'sku.special_price.Start',     // BD
        'sku.special_price.End',       // BE
        'sku.price',                   // BF
        'sku.SellerSku',               // BG
        'sku.package_length',          // BH
        'sku.package_width',           // BI
        'sku.package_height',          // BJ
        'sku.campaignPrice.isAutoJoinABCCampaign', // BK
        'sku.campaignPrice.aPlusCampaignPrice',    // BL
        'sizeChartTool',               // BM  (col 65)
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Test helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a minimal but valid OOXML xlsx that mirrors the REAL Lazada batch-
 * upload template structure:
 *
 *   Sheet 1: "INDEX" — lists the category in A2
 *   Sheet 2: "<categorySheet>" (VISIBLE) — Thai placeholder labels in ROW 1
 *            (matching real Lazada: human-readable Thai headers, NOT machine
 *            keys), rows 2–4 empty preamble, data starts row 5.
 *   Sheet 3: "<categorySheet>_hide" (HIDDEN) — rows 1–2 empty, ROW 3 has
 *            machine keys as inlineStr (real structure; preserved byte-identical
 *            after fill — the engine reads from here via keySheet/keyRow).
 *   Sheet 4: "สถานะ" — sentinel row for byte-identity test.
 *   Sheet 5: "global_hide" (HIDDEN) — md5 token row, preserved byte-identical.
 *
 * Returns the absolute path to the temp xlsx.
 */
function lazadaBuildTemplateXlsx(string $categorySheet = 'รองเท้าผ้าใบผู้ชาย'): string
{
    $path = sys_get_temp_dir().'/lazada-template-test-'.uniqid().'.xlsx';
    $hideSheet = $categorySheet.'_hide';
    $machineKeys = lazadaMachineKeys(); // 65 keys

    // ── Shared strings (INDEX + global_hide only; visible row 1 uses inlineStr) ─

    $allStrings = array_values(array_unique([
        'Leaf Category Tab',                    // INDEX header
        $categorySheet,                         // INDEX row 2
        'status-sentinel',                      // สถานะ sentinel
        'version', 'excelActionType', 'requestActionType', 'fieldType',
        'localName', 'originalLocale', 'excelSystemParam', 'customScene',
        'dynamicHeaderRowCount', 'md5Key',
        '1.0.0', 'EXPORT', 'custom', 'FULL', 'th_TH', '{}',
        'advancedPublish', 'true', 'a24aac9bc4e7c94c3c4509f13ddb555e',
    ]));

    $ssMap = array_flip($allStrings); // string → index

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

    // ── Helper: build a shared-string cell ──────────────────────────────────
    $ss = static function (string $ref, string $value) use ($ssMap): string {
        $idx = $ssMap[$value] ?? 0;

        return "<c r=\"{$ref}\" t=\"s\"><v>{$idx}</v></c>";
    };

    // ── Sheet 1: INDEX ──────────────────────────────────────────────────────
    $escapedCat = htmlspecialchars($categorySheet, ENT_XML1, 'UTF-8');
    $indexSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData>'
        .'<row r="1">'.$ss('A1', 'Leaf Category Tab').'</row>'
        .'<row r="2">'.$ss('A2', $categorySheet).'</row>'
        .'</sheetData></worksheet>';

    // ── Sheet 2: VISIBLE category sheet ─────────────────────────────────────
    // Row 1 = Thai placeholder labels (REAL structure: human-readable headers).
    // The engine reads machine keys from the _hide sheet row 3 — NOT from here.
    // Rows 2–4 = empty preamble. Data rows start at row 5.
    $row1Cells = '';
    foreach ($machineKeys as $i => $key) {
        $colLetter = WorkbookSurgeon::indexToLetter($i + 1);
        // Thai placeholder: "ป้าย-{letter}" — clearly NOT a machine key.
        $label = htmlspecialchars('ป้าย-'.$colLetter, ENT_XML1, 'UTF-8');
        $row1Cells .= "<c r=\"{$colLetter}1\" t=\"inlineStr\"><is><t>{$label}</t></is></c>";
    }

    $visibleSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1:BM4"/>'
        .'<sheetData>'
        ."<row r=\"1\">{$row1Cells}</row>"
        .'<row r="2"></row>'
        .'<row r="3"></row>'
        .'<row r="4"></row>'
        .'</sheetData>'
        .'</worksheet>';

    // ── Sheet 3: _hide sheet (HIDDEN) ───────────────────────────────────────
    // Rows 1–2 = empty (real structure).
    // Row 3  = machine keys as inlineStr at same column positions (real structure).
    // The engine scans this sheet at keyRow=3 to build the column map.
    $row3Cells = '';
    foreach ($machineKeys as $i => $key) {
        $colLetter = WorkbookSurgeon::indexToLetter($i + 1);
        $row3Cells .= "<c r=\"{$colLetter}3\" t=\"inlineStr\"><is><t>"
            .htmlspecialchars($key, ENT_XML1, 'UTF-8')
            .'</t></is></c>';
    }

    $hideSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1"/>'
        .'<sheetData>'
        .'<row r="1"></row>'
        .'<row r="2"></row>'
        ."<row r=\"3\">{$row3Cells}</row>"
        .'</sheetData>'
        .'</worksheet>';

    // ── Sheet 4: สถานะ ──────────────────────────────────────────────────────
    $statusSheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>status-sentinel</t></is></c></row>'
        .'</sheetData>'
        .'</worksheet>';

    // ── Sheet 5: global_hide (HIDDEN) — md5 token ───────────────────────────
    $globalHideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1"/>'
        .'<sheetData>'
        .'<row r="1">'
        .$ss('A1', 'version')
        .$ss('B1', 'excelActionType')
        .$ss('C1', 'requestActionType')
        .$ss('D1', 'fieldType')
        .$ss('E1', 'localName')
        .$ss('F1', 'originalLocale')
        .$ss('G1', 'excelSystemParam')
        .$ss('H1', 'customScene')
        .$ss('I1', 'dynamicHeaderRowCount')
        .$ss('J1', 'md5Key')
        .'</row>'
        .'<row r="2">'
        .$ss('A2', '1.0.0')
        .$ss('B2', 'EXPORT')
        .$ss('C2', 'custom')
        .$ss('D2', 'FULL')
        .$ss('E2', 'th_TH')
        .'<c r="F2"></c>'
        .$ss('G2', '{}')
        .$ss('H2', 'advancedPublish')
        .$ss('I2', 'true')
        .$ss('J2', 'a24aac9bc4e7c94c3c4509f13ddb555e')
        .'</row>'
        .'</sheetData>'
        .'</worksheet>';

    // ── Workbook ─────────────────────────────────────────────────────────────
    $escapedCatXml = htmlspecialchars($categorySheet, ENT_XML1, 'UTF-8');
    $escapedHideXml = htmlspecialchars($hideSheet, ENT_XML1, 'UTF-8');

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'
        .'<sheet name="INDEX" sheetId="1" r:id="rId1"/>'
        ."<sheet name=\"{$escapedCatXml}\" sheetId=\"2\" r:id=\"rId2\"/>"
        ."<sheet name=\"{$escapedHideXml}\" sheetId=\"3\" r:id=\"rId3\" state=\"hidden\"/>"
        .'<sheet name="สถานะ" sheetId="4" r:id="rId4"/>'
        .'<sheet name="global_hide" sheetId="5" r:id="rId5" state="hidden"/>'
        .'</sheets>'
        .'</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'
        .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet5.xml"/>'
        .'<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet5.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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
    $zip->addFromString('xl/worksheets/sheet1.xml', $indexSheetXml);
    $zip->addFromString('xl/worksheets/sheet2.xml', $visibleSheetXml);
    $zip->addFromString('xl/worksheets/sheet3.xml', $hideSheetXml);
    $zip->addFromString('xl/worksheets/sheet4.xml', $statusSheetXml);
    $zip->addFromString('xl/worksheets/sheet5.xml', $globalHideXml);
    $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    return $path;
}

/**
 * Build a MULTI-CATEGORY Lazada template fixture (2 category sheets).
 * Used exclusively for the multi-category fail-loud test.
 */
function lazadaBuildMultiCategoryTemplateXlsx(): string
{
    $path = sys_get_temp_dir().'/lazada-multi-cat-test-'.uniqid().'.xlsx';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'
        .'<sheet name="INDEX" sheetId="1" r:id="rId1"/>'
        .'<sheet name="CategoryA" sheetId="2" r:id="rId2"/>'
        .'<sheet name="CategoryA_hide" sheetId="3" r:id="rId3" state="hidden"/>'
        .'<sheet name="CategoryB" sheetId="4" r:id="rId4"/>'
        .'<sheet name="CategoryB_hide" sheetId="5" r:id="rId5" state="hidden"/>'
        .'<sheet name="global_hide" sheetId="6" r:id="rId6" state="hidden"/>'
        .'</sheets>'
        .'</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'
        .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet5.xml"/>'
        .'<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet6.xml"/>'
        .'</Relationships>';

    $minSheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData></sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet5.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet6.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    foreach (range(1, 6) as $i) {
        $zip->addFromString("xl/worksheets/sheet{$i}.xml", $minSheet);
    }
    $zip->close();

    return $path;
}

/**
 * Extract all zip entries from an xlsx as path → raw content.
 *
 * @return array<string, string>
 */
function lazadaExtractZipEntries(string $zipPath): array
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
 * Read a filled cell value from the Lazada visible category sheet (sheet2.xml)
 * by column key prefix + row index.
 *
 * Column positions are resolved from the _hide sheet's physical row 3
 * (where the machine keys live in the real Lazada template structure).
 */
function lazadaReadFilledCell(
    string $filledPath,
    string $colKeyPrefix,
    int $rowIndex,
    string $categorySheet = 'รองเท้าผ้าใบผู้ชาย',
): ?string {
    // Resolve column index from the _hide sheet row 3 (real template structure).
    $surgeon = new WorkbookSurgeon($filledPath);
    $colIdx = $surgeon->columnIndex($categorySheet.'_hide', $colKeyPrefix, 3);

    if ($colIdx === null) {
        return null;
    }

    $z = new ZipArchive;
    $z->open($filledPath);
    // Visible category sheet is always sheet2.xml in our test fixture.
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
        /** @var DOMElement $c */
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
 * Create a Lazada marketplace Shop with the default Location.
 */
function lazadaFillShop(string $name = 'lazada-fill'): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle($name, Platform::Lazada, $location);
}

/**
 * Create a Product with Variants + stock at the default Location.
 *
 * @param  list<array{master_sku: string, list_price: Money, name?: string|null, barcode?: string|null, package_weight_g?: int|null, package_width_mm?: int|null, package_length_mm?: int|null, package_height_mm?: int|null}>  $variants
 */
function lazadaFillProduct(string $name, array $variants, int $onHand = 5): Product
{
    $product = app(CreateProduct::class)->handle($name, $variants);
    $location = Location::query()->where('is_default', true)->firstOrFail();

    foreach ($product->variants as $variant) {
        app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, $onHand);
    }

    return $product->load(['variants', 'images']);
}

/**
 * Store a blank Lazada template + create an ImportJob for RunTemplateFillJob.
 *
 * The job resolves the target sheet via LazadaTemplateFiller::resolveTargetSheet()
 * → detectFromWorkbook() on the stored file — no IoC pre-binding required.
 *
 * @param  int[]  $variantIds
 */
function lazadaMakeTemplateFillJob(
    Shop $shop,
    array $variantIds,
    string $diskKey = 'imports/laz/t.xlsx',
    string $categorySheet = 'รองเท้าผ้าใบผู้ชาย',
): ImportJob {
    $templatePath = lazadaBuildTemplateXlsx($categorySheet);
    $templateContents = file_get_contents($templatePath);

    if ($templateContents === false) {
        throw new RuntimeException("Cannot read template: {$templatePath}");
    }

    Storage::disk('local')->put($diskKey, $templateContents);

    return ImportJob::query()->create([
        'importer' => LazadaTemplateFiller::class,
        'original_filename' => 'lazada-template.xlsx',
        'stored_path' => $diskKey,
        'status' => ImportJobStatus::Pending,
        'context' => ['shop_id' => $shop->id, 'variant_ids' => $variantIds],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// WorkbookSurgeon — byte-identity invariants
// ─────────────────────────────────────────────────────────────────────────────

it('Lazada: non-target sheet entries (including _hide + global_hide) are content-identical after fill', function () {
    $shop = lazadaFillShop('lazada-bi');
    $product = lazadaFillProduct('สินค้า BI', [
        ['master_sku' => 'BI-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ], onHand: 3);
    $product->update(['description' => 'คำอธิบาย BI']);
    $product->images()->create(['path' => 'img/bi.jpg', 'sort_order' => 1]);
    $product->load('images');

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected a variant.');
    $importJob = lazadaMakeTemplateFillJob($shop, [$variant->id], 'imports/laz/bi.xlsx');

    $templateContents = Storage::disk('local')->get('imports/laz/bi.xlsx');
    if ($templateContents === false) {
        throw new RuntimeException('Could not read template from storage.');
    }
    $srcPath = sys_get_temp_dir().'/lazada-bi-src-'.uniqid().'.xlsx';
    file_put_contents($srcPath, $templateContents);

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;

    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }

    $fullPath = Storage::disk('local')->path($resultPath);
    $srcEntries = lazadaExtractZipEntries($srcPath);
    $dstEntries = lazadaExtractZipEntries($fullPath);

    expect(array_keys($srcEntries))->each->toBeIn(array_keys($dstEntries));

    // Every entry except the modified visible category sheet must be byte-identical.
    // This verifies _hide, global_hide, INDEX, สถานะ, sharedStrings, styles, etc.
    foreach ($srcEntries as $name => $srcContent) {
        if ($name === 'xl/worksheets/sheet2.xml') {
            continue; // visible category sheet — expected to change
        }

        expect($dstEntries[$name])->toBe(
            $srcContent,
            "Zip entry '{$name}' must be content-identical after fill (byte-identity invariant)."
        );
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Column fill correctness
// ─────────────────────────────────────────────────────────────────────────────

it('fills all owned Lazada columns correctly for a 2-variant Product', function () {
    $shop = lazadaFillShop('lazada-cols');

    // 12550 satang → "125.50" baht for sku.price.
    $product = lazadaFillProduct('รองเท้า Lazada Test', [
        [
            'master_sku' => 'LAZ-RED-M',
            'name' => 'แดง / M',
            'list_price' => Money::fromSatang(12550),
            'package_weight_g' => 500,
            'package_length_mm' => 300,
            'package_width_mm' => 200,
            'package_height_mm' => 100,
        ],
        [
            'master_sku' => 'LAZ-RED-L',
            'name' => 'แดง / L',
            'list_price' => Money::fromSatang(12550),
        ],
    ], onHand: 7);

    $product->update([
        'description' => 'คำอธิบายสินค้า Lazada',
        'english_name' => 'Lazada Test Shoes',
        'brand' => 'Deblu',
    ]);

    $product->images()->create(['path' => 'img/main.jpg', 'sort_order' => 1]);
    $product->images()->create(['path' => 'img/extra.jpg', 'sort_order' => 2]);
    $product->load('images');

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();
    $importJob = lazadaMakeTemplateFillJob($shop, $variantIds, 'imports/laz/cols.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    expect($importJob->status)->toBe(ImportJobStatus::Completed)
        ->and($importJob->error_rows)->toBe(0);

    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;

    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }

    $fullPath = Storage::disk('local')->path($resultPath);

    // ── Row 5: first variant (sorted by id) ──────────────────────────────

    // Group number: first product in the batch → group 1.
    expect(lazadaReadFilledCell($fullPath, 'productNoForBatch', 5))->toBe('1');

    // Thai title and English title.
    expect(lazadaReadFilledCell($fullPath, 'title.th_TH', 5))->toBe('รองเท้า Lazada Test')
        ->and(lazadaReadFilledCell($fullPath, 'title.en_TH', 5))->toBe('Lazada Test Shoes');

    // Description.
    expect(lazadaReadFilledCell($fullPath, 'description', 5))->toBe('คำอธิบายสินค้า Lazada');

    // Brand via catProperty.p-20000.
    expect(lazadaReadFilledCell($fullPath, 'catProperty.p-20000', 5))->toBe('Deblu');

    // SKU.
    expect(lazadaReadFilledCell($fullPath, 'sku.SellerSku', 5))->toBe('LAZ-RED-M');

    // Price: 12550 satang → "125.50" baht.
    expect(lazadaReadFilledCell($fullPath, 'sku.price', 5))->toBe('125.50');

    // Stock: 7 on-hand, 0 reserved → available = 7.
    expect(lazadaReadFilledCell($fullPath, 'sku.quantity', 5))->toBe('7');

    // Weight: 500g → 0.5 kg.
    expect(lazadaReadFilledCell($fullPath, 'sku.package_weight', 5))->toBe('0.5');

    // Dimensions: 300mm → 30cm, 200mm → 20cm, 100mm → 10cm.
    expect(lazadaReadFilledCell($fullPath, 'sku.package_length', 5))->toBe('30')
        ->and(lazadaReadFilledCell($fullPath, 'sku.package_width', 5))->toBe('20')
        ->and(lazadaReadFilledCell($fullPath, 'sku.package_height', 5))->toBe('10');

    // Main images.
    expect(lazadaReadFilledCell($fullPath, 'mainImage.0', 5))->toContain('main.jpg')
        ->and(lazadaReadFilledCell($fullPath, 'mainImage.1', 5))->toContain('extra.jpg');

    // marketImages.1:1 = primary image.
    expect(lazadaReadFilledCell($fullPath, 'marketImages.1:1', 5))->toContain('main.jpg');

    // sku.images.0 = first product image (no variant-scoped images).
    expect(lazadaReadFilledCell($fullPath, 'sku.images.0', 5))->toContain('main.jpg');

    // ── Row 6: second variant ─────────────────────────────────────────────

    // Same group number (same product).
    expect(lazadaReadFilledCell($fullPath, 'productNoForBatch', 6))->toBe('1');

    expect(lazadaReadFilledCell($fullPath, 'sku.SellerSku', 6))->toBe('LAZ-RED-L')
        ->and(lazadaReadFilledCell($fullPath, 'sku.price', 6))->toBe('125.50')
        ->and(lazadaReadFilledCell($fullPath, 'title.th_TH', 6))->toBe('รองเท้า Lazada Test');
});

it('two Products get different group numbers (productNoForBatch)', function () {
    $shop = lazadaFillShop('lazada-grp');

    $productA = lazadaFillProduct('สินค้า A', [
        ['master_sku' => 'GRP-A-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $productA->update(['description' => 'คำอธิบาย A']);
    $productA->images()->create(['path' => 'img/a.jpg', 'sort_order' => 1]);
    $productA->load('images');

    $productB = lazadaFillProduct('สินค้า B', [
        ['master_sku' => 'GRP-B-1', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);
    $productB->update(['description' => 'คำอธิบาย B']);
    $productB->images()->create(['path' => 'img/b.jpg', 'sort_order' => 1]);
    $productB->load('images');

    $variantA = $productA->variants->first() ?? throw new RuntimeException('Expected variant A.');
    $variantB = $productB->variants->first() ?? throw new RuntimeException('Expected variant B.');

    // Pass A first, then B — they should get group 1 and 2 respectively.
    $importJob = lazadaMakeTemplateFillJob($shop, [$variantA->id, $variantB->id], 'imports/laz/grp.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;

    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }

    $fullPath = Storage::disk('local')->path($resultPath);

    expect(lazadaReadFilledCell($fullPath, 'productNoForBatch', 5))->toBe('1')
        ->and(lazadaReadFilledCell($fullPath, 'productNoForBatch', 6))->toBe('2');
});

it('leaves non-owned columns untouched (catId, sku.shop_sku, catProperty.p-31051, etc.) after fill', function () {
    $shop = lazadaFillShop('lazada-fk');
    $product = lazadaFillProduct('สินค้า FK', [
        ['master_sku' => 'FK-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย FK']);
    $product->images()->create(['path' => 'img/fk.jpg', 'sort_order' => 1]);
    $product->load('images');

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected variant.');
    $importJob = lazadaMakeTemplateFillJob($shop, [$variant->id], 'imports/laz/fk.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();
    $context = $importJob->context;
    $resultPath = is_array($context) ? ($context['result_path'] ?? null) : null;

    if (! is_string($resultPath)) {
        throw new RuntimeException('Expected result_path after fill.');
    }

    $fullPath = Storage::disk('local')->path($resultPath);

    // catId is NOT owned — must be absent.
    expect(lazadaReadFilledCell($fullPath, 'catId', 5))->toBeNull();

    // sku.shop_sku is Lazada's internal platform SKU — NOT owned.
    expect(lazadaReadFilledCell($fullPath, 'sku.shop_sku', 5))->toBeNull();

    // Other catProperty.* (not p-20000) — NOT owned.
    expect(lazadaReadFilledCell($fullPath, 'catProperty.p-31051', 5))->toBeNull();

    // saleProp — NOT owned.
    expect(lazadaReadFilledCell($fullPath, 'saleProp.p-30097', 5))->toBeNull();

    // Deal-price columns — NOT owned (Phase 7).
    expect(lazadaReadFilledCell($fullPath, 'sku.special_price.SpecialPrice', 5))->toBeNull();
});

it('stock clamped to 0 when Available is negative', function () {
    $shop = lazadaFillShop('lazada-neg');
    $location = Location::query()->where('is_default', true)->firstOrFail();

    $product = app(CreateProduct::class)->handle('สินค้า Neg Laz', [
        ['master_sku' => 'LAZ-NEG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);
    $product->images()->create(['path' => 'img/neg.jpg', 'sort_order' => 1]);
    $product->load('images');

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected a variant.');

    // Push on-hand to 1, then SHIP 5 (oversell) → Available goes negative.
    app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 1);
    app(AppendStockMovement::class)->handle($variant, $location, StockAction::Ship, 5, null, null, 0);

    $importJob = lazadaMakeTemplateFillJob($shop, [$variant->id], 'imports/laz/neg.xlsx');

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
    expect(lazadaReadFilledCell($fullPath, 'sku.quantity', 5))->toBe('0');
});

// ─────────────────────────────────────────────────────────────────────────────
// Fail-loud held rows (ADR 0005)
// ─────────────────────────────────────────────────────────────────────────────

it('Lazada: image-less Variant is HELD fail-loud while good rows still fill', function () {
    $shop = lazadaFillShop('lazada-img');

    $goodProduct = lazadaFillProduct('สินค้า GOOD-LAZ', [
        ['master_sku' => 'LAZ-GOOD-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $goodProduct->update(['description' => 'คำอธิบาย good']);
    $goodProduct->images()->create(['path' => 'img/good.jpg', 'sort_order' => 1]);
    $goodProduct->load('images');

    $badProduct = lazadaFillProduct('สินค้า BAD-LAZ', [
        ['master_sku' => 'LAZ-BAD-1', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);
    $badProduct->update(['description' => 'คำอธิบาย bad']);
    // badProduct intentionally has NO images — Lazada requires at least one.

    $goodVariant = $goodProduct->variants->first() ?? throw new RuntimeException('Expected good variant.');
    $badVariant = $badProduct->variants->first() ?? throw new RuntimeException('Expected bad variant.');

    $importJob = lazadaMakeTemplateFillJob($shop, [$badVariant->id, $goodVariant->id], 'imports/laz/img.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain('LAZ-BAD-1');

    // Good variant got a draft ListingVariant.
    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $goodVariant->id)
        ->exists()
    )->toBeTrue();

    // Bad variant was held — no ListingVariant.
    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $badVariant->id)
        ->exists()
    )->toBeFalse();
});

it('Lazada: Variant with no description is held fail-loud while good rows still fill', function () {
    $shop = lazadaFillShop('lazada-desc');

    $goodProduct = lazadaFillProduct('สินค้า GOOD-DESC', [
        ['master_sku' => 'LAZ-GDESC-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $goodProduct->update(['description' => 'คำอธิบาย good']);
    $goodProduct->images()->create(['path' => 'img/gdesc.jpg', 'sort_order' => 1]);
    $goodProduct->load('images');

    $badProduct = lazadaFillProduct('สินค้า BAD-DESC', [
        ['master_sku' => 'LAZ-BDESC-1', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);
    // badProduct has NO description.
    $badProduct->images()->create(['path' => 'img/bdesc.jpg', 'sort_order' => 1]);
    $badProduct->load('images');

    $goodVariant = $goodProduct->variants->first() ?? throw new RuntimeException('Expected good variant.');
    $badVariant = $badProduct->variants->first() ?? throw new RuntimeException('Expected bad variant.');

    $importJob = lazadaMakeTemplateFillJob($shop, [$badVariant->id, $goodVariant->id], 'imports/laz/desc.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $importJob->refresh();

    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain('LAZ-BDESC-1');
});

// ─────────────────────────────────────────────────────────────────────────────
// Multi-category detection
// ─────────────────────────────────────────────────────────────────────────────

it('detectFromWorkbook fails loud with Thai message when >1 category sheets', function () {
    $multiPath = lazadaBuildMultiCategoryTemplateXlsx();

    expect(static fn () => LazadaTemplateFiller::detectFromWorkbook($multiPath))
        ->toThrow(RowImportException::class);
});

it('detectFromWorkbook returns the single category sheet name', function () {
    $singlePath = lazadaBuildTemplateXlsx('รองเท้าผ้าใบผู้ชาย');

    expect(LazadaTemplateFiller::detectFromWorkbook($singlePath))
        ->toBe('รองเท้าผ้าใบผู้ชาย');
});

// ─────────────────────────────────────────────────────────────────────────────
// ListingVariant upsert logic
// ─────────────────────────────────────────────────────────────────────────────

it('Lazada: creates a draft ListingVariant (platform_sku = master_sku) for each filled Variant', function () {
    $shop = lazadaFillShop('lazada-lv');
    $product = lazadaFillProduct('สินค้า LV-LAZ', [
        ['master_sku' => 'LAZ-LV-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);
    $product->images()->create(['path' => 'img/lv.jpg', 'sort_order' => 1]);
    $product->load('images');

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected a variant.');
    $importJob = lazadaMakeTemplateFillJob($shop, [$variant->id], 'imports/laz/lv.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    $lv = ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->firstOrFail();

    expect($lv->listing_status)->toBe(ListingStatus::Draft)
        ->and($lv->platform_sku)->toBe('LAZ-LV-1');
});

it('Lazada: never downgrades an existing listed ListingVariant to draft on re-fill', function () {
    $shop = lazadaFillShop('lazada-ndg');
    $product = lazadaFillProduct('สินค้า NDG-LAZ', [
        ['master_sku' => 'LAZ-NDG-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);
    $product->images()->create(['path' => 'img/ndg.jpg', 'sort_order' => 1]);
    $product->load('images');

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected a variant.');

    // Pre-seed a `listed` ListingVariant.
    $listing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $product->id,
    ]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'LAZ-NDG-1',
        'listing_status' => ListingStatus::Listed,
    ]);

    $importJob = lazadaMakeTemplateFillJob($shop, [$variant->id], 'imports/laz/ndg.xlsx');

    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');
    (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();

    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->firstOrFail()
        ->listing_status
    )->toBe(ListingStatus::Listed);
});

it('Lazada: re-fill is idempotent — running three times creates only one ListingVariant', function () {
    $shop = lazadaFillShop('lazada-idem');
    $product = lazadaFillProduct('สินค้า IDEM-LAZ', [
        ['master_sku' => 'LAZ-IDEM-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);
    $product->images()->create(['path' => 'img/idem.jpg', 'sort_order' => 1]);
    $product->load('images');

    $variant = $product->variants->first() ?? throw new RuntimeException('Expected a variant.');
    $tenant = app(TenantContext::class)->current() ?? throw new RuntimeException('No active tenant.');

    foreach (range(1, 3) as $run) {
        $importJob = lazadaMakeTemplateFillJob($shop, [$variant->id], "imports/laz/idem-{$run}.xlsx");
        (new RunTemplateFillJob($importJob->id, $tenant->id))->handle();
    }

    expect(ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('variant_id', $variant->id)
        ->count()
    )->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Cross-tenant isolation
// ─────────────────────────────────────────────────────────────────────────────

it('Lazada: cross-tenant variant_ids are invisible — reported as not-found errors', function () {
    $shopA = lazadaFillShop('lazada-ct-a');

    // Tenant B: create its own variant.
    $tenantB = app(CreateTenant::class)->handle('LB');
    app(TenantContext::class)->set($tenantB);

    $productB = app(CreateProduct::class)->handle('สินค้า LB', [
        ['master_sku' => 'LB-SKU-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $variantB = $productB->variants->first() ?? throw new RuntimeException('Expected variant B.');
    app(TenantContext::class)->forget();

    // Switch back to Tenant A.
    $tenantA = Tenant::query()->where('name', 'LA')->firstOrFail();
    app(TenantContext::class)->set($tenantA);
    Storage::fake('local');
    actingAs(User::factory()->create());

    $templatePath = lazadaBuildTemplateXlsx();
    $templateContents = file_get_contents($templatePath);

    if ($templateContents === false) {
        throw new RuntimeException("Cannot read template: {$templatePath}");
    }

    Storage::disk('local')->put("imports/{$tenantA->id}/laz-ct.xlsx", $templateContents);

    $importJob = ImportJob::query()->create([
        'importer' => LazadaTemplateFiller::class,
        'original_filename' => 'laz-ct.xlsx',
        'stored_path' => "imports/{$tenantA->id}/laz-ct.xlsx",
        'status' => ImportJobStatus::Pending,
        'context' => ['shop_id' => $shopA->id, 'variant_ids' => [$variantB->id]],
    ]);

    (new RunTemplateFillJob($importJob->id, $tenantA->id))->handle();

    $importJob->refresh();

    // Tenant B's variant is invisible under Tenant A's RLS → "not found" error.
    expect($importJob->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($importJob->error_rows)->toBe(1)
        ->and(collect($importJob->errors)->pluck('message')->implode(' '))->toContain((string) $variantB->id);

    expect(ListingVariant::query()->where('shop_id', $shopA->id)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// StartTemplateFill pipeline
// ─────────────────────────────────────────────────────────────────────────────

it('Lazada: StartTemplateFill dispatches RunTemplateFillJob with correct ImportJob', function () {
    Queue::fake();

    $shop = lazadaFillShop('lazada-dispatch');
    $product = lazadaFillProduct('สินค้า Dispatch-LAZ', [
        ['master_sku' => 'LAZ-DISPATCH-1', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $product->update(['description' => 'คำอธิบาย']);
    $product->images()->create(['path' => 'img/dispatch.jpg', 'sort_order' => 1]);

    $variantIds = $product->variants->map(static fn (Variant $v): int => $v->id)->all();

    $templatePath = lazadaBuildTemplateXlsx();
    $file = new UploadedFile($templatePath, 'lazada-template.xlsx', null, null, true);

    $importJob = app(StartTemplateFill::class)->handle(
        $file,
        LazadaTemplateFiller::class,
        ['shop_id' => $shop->id, 'variant_ids' => $variantIds],
    );

    Queue::assertPushed(RunTemplateFillJob::class, static function (RunTemplateFillJob $job) use ($importJob): bool {
        return $job->importJobId === $importJob->id;
    });

    $jobContext = $importJob->context;

    if (! is_array($jobContext)) {
        throw new RuntimeException('Expected context to be an array.');
    }

    expect($importJob->importer)->toBe(LazadaTemplateFiller::class)
        ->and($jobContext['shop_id'] ?? null)->toBe($shop->id)
        ->and($jobContext['variant_ids'] ?? null)->toBe($variantIds);
});
