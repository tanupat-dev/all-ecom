<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Imports\LazadaAllProductImporter;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

use function Pest\Laravel\actingAs;

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

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

/**
 * The 15 column headers from `ref doc/lazada/All product lazada.xlsx`
 * Row 1, Sheet 1 (template). The pipeline anchors on these as column keys.
 * Mixed Thai/English — the identifiers used by the importer are purely
 * English: `Product ID` (col 0) and `SellerSKU` (col 12).
 *
 * @return list<string>
 */
function lazadaAllProductHeaders(): array
{
    return [
        'Product ID',
        'catId',
        'ชื่อสินค้า',
        'currencyCode',
        'sku.skuId',
        'status',
        'ร้าน sku',
        'จำนวน',
        'SpecialPrice',
        'SpecialPrice Start',
        'SpecialPrice End',
        'ราคา',
        'SellerSKU',
        'Variations Combo',
        'tr(s-wb-product@md5key)',
    ];
}

/**
 * The 3 preamble rows Lazada inserts before actual data (verified from the
 * reference file):
 *  Row 2 — required/optional markers (Thai)
 *  Row 3 — field descriptions (Thai)
 *  Row 4 — constraints / validation hints (Thai)
 *
 * @return list<list<string>>
 */
function lazadaAllProductPreambleRows(): array
{
    return [
        // Row 2: required/optional markers
        [
            'ไม่บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล', 'บังคับการกรอกข้อมูล',
            'ไม่บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล',
            'ไม่บังคับการกรอกข้อมูล', 'บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล',
            'ไม่บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล', 'บังคับการกรอกข้อมูล',
            'ไม่บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล', 'ไม่บังคับการกรอกข้อมูล',
        ],
        // Row 3: field descriptions
        [
            'ระบบสร้างรหัสตัวเลขที่แสดงถึงสินค้าของคุณ', '', 'ชื่อสินค้าควรมียี่ห้อสินค้าและรุ่น',
            '', '', '',
            'Shop SKU เรียกอีกอย่างว่า Lazada SKU', 'ใส่สต๊อกสินค้าของคุณ', 'สินค้าราคาพิเศษของสินค้าของคุณ',
            'วันที่เริ่มต้นราคาพิเศษ', 'วันที่สิ้นสุดราคาพิเศษ', 'ป้อนราคาสินค้าของคุณ',
            'SKU คือข้อมูลที่เป็นรูปแบบเฉพาะ', 'รูปแบบของสินค้าคือตัวเลือกของสินค้า', '',
        ],
        // Row 4: constraints / validation hints
        [
            '', '', '*กรุณาใส่ 5 ถึง 255 ตัวอักษรสำหรับชื่อสินค้า',
            '', '', 'ถ้าเว้นว่างไว้ระหว่างการแก้ไขแบบกลุ่มจะถือว่าไม่มีการเปลี่ยนแปลง',
            '', '*ยอมรับเฉพาะตัวเลขที่มีค่าเป็นบวกเท่านั้น', '',
            '', '', '*ยอมรับเฉพาะตัวเลขที่มีค่าเป็นบวกเท่านั้น',
            'กรุณาใส่น้อยกว่า 200 ตัวอักษร', '', '',
        ],
    ];
}

/**
 * Write a Lazada "All product" xlsx that mirrors the real file's structure:
 * Row 1 = column headers, Rows 2–4 = preamble, Row 5+ = data.
 *
 * @param  list<array<string, string>>  $dataRows  header => value
 */
function writeLazadaAllProductXlsx(array $dataRows): string
{
    $headers = lazadaAllProductHeaders();
    $path = sys_get_temp_dir().'/lazada-allproduct-test-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    // Row 1: column headers (the pipeline's column keys)
    $writer->addRow(Row::fromValues($headers));

    // Rows 2–4: preamble rows
    foreach (lazadaAllProductPreambleRows() as $preamble) {
        $writer->addRow(Row::fromValues(array_pad($preamble, count($headers), '')));
    }

    // Actual data rows (Row 5+)
    foreach ($dataRows as $dataRow) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $header): string => (string) ($dataRow[$header] ?? ''),
            $headers,
        )));
    }

    $writer->close();

    return $path;
}

function lazadaAllProductShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle('lazada1', Platform::Lazada, $location);
}

function lazadaAllProductCatalog(): void
{
    app(CreateProduct::class)->handle('รองเท้าแตะ Tiseng', [
        ['master_sku' => 'Tiseng-พื้นหนัง.140.แทน.39', 'name' => 'แทน / EU:39', 'list_price' => Money::fromBaht('550')],
        ['master_sku' => 'Tiseng-พื้นหนัง.140.แทน.38', 'name' => 'แทน / EU:38', 'list_price' => Money::fromBaht('550')],
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('creates Listing + ListingVariant (listed) and the resolver map from the real file structure', function () {
    $shop = lazadaAllProductShop();
    lazadaAllProductCatalog();

    $file = new UploadedFile(writeLazadaAllProductXlsx([
        [
            'Product ID' => '344616516',
            'ชื่อสินค้า' => 'รองเท้าแตะ Tiseng',
            'sku.skuId' => '11537345070',
            'status' => 'active',
            'จำนวน' => '10',
            'ราคา' => '550.00',
            'SellerSKU' => 'Tiseng-พื้นหนัง.140.แทน.39',
            'Variations Combo' => 'แทน,EU: 39',
        ],
        [
            'Product ID' => '344616516',
            'ชื่อสินค้า' => 'รองเท้าแตะ Tiseng',
            'sku.skuId' => '11537345068',
            'status' => 'active',
            'จำนวน' => '5',
            'ราคา' => '550.00',
            'SellerSKU' => 'Tiseng-พื้นหนัง.140.แทน.38',
            'Variations Combo' => 'แทน,EU: 38',
        ],
    ]), 'All product lazada.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0);

    // One Listing for the product (two variants share one Listing)
    expect(Listing::query()->where('shop_id', $shop->id)->count())->toBe(1);

    // Two ListingVariant rows — both listed (ground truth from Platform reality)
    $lvs = ListingVariant::query()->where('shop_id', $shop->id)->get();
    expect($lvs)->toHaveCount(2)
        ->and($lvs->every(fn ($lv) => $lv->listing_status === ListingStatus::Listed))->toBeTrue();

    // Resolver map is populated: (shop, platform_sku) resolves to the Variant
    $lv1 = ListingVariant::query()
        ->where('shop_id', $shop->id)
        ->where('platform_sku', 'Tiseng-พื้นหนัง.140.แทน.39')
        ->firstOrFail();
    expect($lv1->variant()->firstOrFail()->master_sku)->toBe('Tiseng-พื้นหนัง.140.แทน.39');
});

it('holds a row whose SellerSKU has no matching Master SKU — fail-loud, ADR 0005', function () {
    $shop = lazadaAllProductShop();
    lazadaAllProductCatalog();

    $file = new UploadedFile(writeLazadaAllProductXlsx([
        // This SKU exists in the catalog
        [
            'Product ID' => '344616516',
            'SellerSKU' => 'Tiseng-พื้นหนัง.140.แทน.39',
            'ราคา' => '550.00',
            'จำนวน' => '10',
        ],
        // This SKU is NOT in the catalog
        [
            'Product ID' => '344616516',
            'SellerSKU' => 'SKU-NOT-IN-CATALOG',
            'ราคา' => '550.00',
            'จำนวน' => '5',
        ],
    ]), 'All product lazada.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    // The valid row still lands; the missing-SKU row is held
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SKU-NOT-IN-CATALOG')
        ->and(ListingVariant::query()->where('platform_sku', 'Tiseng-พื้นหนัง.140.แทน.39')->exists())->toBeTrue()
        ->and(ListingVariant::query()->where('platform_sku', 'SKU-NOT-IN-CATALOG')->exists())->toBeFalse();
});

it('holds a row where the SellerSKU already resolves to a different Variant — SKU conflict, fail-loud', function () {
    $shop = lazadaAllProductShop();

    app(CreateProduct::class)->handle('สินค้า A', [
        ['master_sku' => 'SKU-A', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);

    // Plant an existing ListingVariant that maps 'SKU-A' to a different variant
    $conflictProduct = app(CreateProduct::class)->handle('Conflict Product', [
        ['master_sku' => 'CONFLICT-ORIGINAL', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $conflictListing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $conflictProduct->id,
    ]);
    $conflictVariant = $conflictProduct->variants()->firstOrFail();

    // Manually plant: platform_sku = 'SKU-A' → conflictVariant (NOT SKU-A's own variant)
    $conflictListing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $conflictVariant->id,
        'platform_sku' => 'SKU-A',  // same platform_sku as SKU-A but different variant!
        'listing_status' => ListingStatus::Listed,
    ]);

    $file = new UploadedFile(writeLazadaAllProductXlsx([
        [
            'Product ID' => '11111111',
            'SellerSKU' => 'SKU-A',
            'ราคา' => '100.00',
            'จำนวน' => '10',
        ],
    ]), 'All product lazada.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SKU-A');
});

it('re-importing the same file is idempotent — no duplicates created', function () {
    $shop = lazadaAllProductShop();
    lazadaAllProductCatalog();

    $rows = [
        [
            'Product ID' => '344616516',
            'SellerSKU' => 'Tiseng-พื้นหนัง.140.แทน.39',
            'ราคา' => '550.00',
            'จำนวน' => '10',
        ],
    ];

    foreach ([1, 2, 3] as $run) {
        $file = new UploadedFile(
            writeLazadaAllProductXlsx($rows),
            'All product lazada.xlsx',
            null, null, true,
        );
        app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shop->id]);
    }

    expect(Listing::query()->where('shop_id', $shop->id)->count())->toBe(1)
        ->and(ListingVariant::query()->where('shop_id', $shop->id)->count())->toBe(1);
});

it('flips a draft ListingVariant to listed — Platform export is ground truth', function () {
    $shop = lazadaAllProductShop();
    lazadaAllProductCatalog();

    // Pre-create the Listing and ListingVariant in `draft` status (as the
    // Channel Upload Template fill engine would do for a Lazada listing)
    $product = Product::query()->where('name', 'รองเท้าแตะ Tiseng')->firstOrFail();
    $variant = Variant::query()->where('master_sku', 'Tiseng-พื้นหนัง.140.แทน.39')->firstOrFail();
    $listing = Listing::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'Tiseng-พื้นหนัง.140.แทน.39',
        'listing_status' => ListingStatus::Draft,
    ]);

    expect(ListingVariant::query()->where('platform_sku', 'Tiseng-พื้นหนัง.140.แทน.39')->firstOrFail()->listing_status)
        ->toBe(ListingStatus::Draft);

    // Import from Platform reality → must flip to listed
    $file = new UploadedFile(writeLazadaAllProductXlsx([
        [
            'Product ID' => '344616516',
            'SellerSKU' => 'Tiseng-พื้นหนัง.140.แทน.39',
            'ราคา' => '550.00',
            'จำนวน' => '10',
        ],
    ]), 'All product lazada.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and(ListingVariant::query()->where('platform_sku', 'Tiseng-พื้นหนัง.140.แทน.39')->firstOrFail()->listing_status)
        ->toBe(ListingStatus::Listed);
});

it('does not read another tenant\'s Variants — cross-tenant isolation', function () {
    // Tenant A (current context): no catalog
    $shopA = lazadaAllProductShop();

    // Tenant B: has the catalog
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $locationB = Location::query()->where('is_default', true)->firstOrFail();
    app(CreateShop::class)->handle('lazada-b', Platform::Lazada, $locationB);
    app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'SKU-B-ONLY', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    app(TenantContext::class)->forget();

    // Switch back to Tenant A, then import a file referencing Tenant B's SKU
    app(TenantContext::class)->set(Tenant::query()->where('name', 'A')->firstOrFail());
    Storage::fake('local');
    actingAs(User::factory()->create());

    $file = new UploadedFile(writeLazadaAllProductXlsx([
        [
            'Product ID' => '99999999',
            'SellerSKU' => 'SKU-B-ONLY',   // exists only in Tenant B
            'ราคา' => '100.00',
            'จำนวน' => '5',
        ],
    ]), 'All product lazada.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shopA->id]);
    $job->refresh();

    // Tenant A cannot see Tenant B's variants — must fail-loud with unmatched SKU
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(ListingVariant::query()->where('shop_id', $shopA->id)->count())->toBe(0);
});

it('preamble rows are not counted as import errors', function () {
    $shop = lazadaAllProductShop();
    lazadaAllProductCatalog();

    // A file with 3 preamble rows and one valid data row
    $file = new UploadedFile(writeLazadaAllProductXlsx([
        [
            'Product ID' => '344616516',
            'SellerSKU' => 'Tiseng-พื้นหนัง.140.แทน.39',
            'ราคา' => '550.00',
            'จำนวน' => '10',
        ],
    ]), 'All product lazada.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    // 3 preamble rows + 1 data row = 4 processed; 0 errors
    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0)
        ->and($job->processed_rows)->toBe(4);
});
