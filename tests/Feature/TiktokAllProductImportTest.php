<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Imports\TiktokAllProductImporter;
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
 * The 8 named machine-key column headers from `ref doc/tiktok/All product
 * tiktok.xlsx` Row 1, Sheet 1 (Template). The pipeline anchors on these as
 * column keys (ADR 0019: anchored on machine-key row, not Thai labels).
 * The identifiers used by the importer are `product_id` (col 0) and
 * `seller_sku` (col 7).
 *
 * @return list<string>
 */
function tiktokAllProductHeaders(): array
{
    return [
        'product_id',
        'category',
        'product_name',
        'sku_id',
        'variation_value',
        'price',
        'quantity',
        'seller_sku',
    ];
}

/**
 * The 4 preamble rows TikTok inserts before actual data (verified from the
 * reference file):
 *  Row 2 — version tag (`V4`) + `Sales_Information` metadata
 *  Row 3 — Thai column labels
 *  Row 4 — required-field markers (`บังคับ` / `ไม่บังคับ`)
 *  Row 5 — instruction / constraint text
 *
 * @return list<list<string>>
 */
function tiktokAllProductPreambleRows(): array
{
    return [
        // Row 2: version metadata
        ['V4', 'Sales_Information', '', '', '', '', '', ''],
        // Row 3: Thai column labels
        ['รหัสสินค้า', 'หมวดหมู่', 'ชื่อสินค้า', 'SKU ID', 'ตัวเลือกของตัวแปร', 'ราคาขายปลีก (สกุลเงินท้องถิ่น)', 'ปริมาณ', 'SKU ของผู้ขาย'],
        // Row 4: required/optional markers
        ['บังคับ', 'บังคับ', 'บังคับ', 'บังคับ', 'บังคับ', 'บังคับ', 'บังคับ', 'ไม่บังคับ'],
        // Row 5: instruction text
        ['ไม่สามารถแก้ไขได้', 'ไม่สามารถแก้ไขได้', 'ไม่สามารถแก้ไขได้', 'ไม่สามารถแก้ไขได้', 'ไม่สามารถแก้ไขได้', 'กรอกราคาสินค้าหรือตัวแปรของสินค้า', 'ปริมาณสต็อกของสินค้า', 'ตัวระบุสินค้าหรือรูปแบบอื่นของสินค้า'],
    ];
}

/**
 * Write a TikTok "All product" xlsx that mirrors the real file's structure:
 * Row 1 = machine-key headers, Rows 2–5 = preamble, Row 6+ = data.
 *
 * @param  list<array<string, string>>  $dataRows  header => value
 */
function writeTiktokAllProductXlsx(array $dataRows): string
{
    $headers = tiktokAllProductHeaders();
    $path = sys_get_temp_dir().'/tiktok-allproduct-test-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    // Row 1: machine-key headers (the pipeline's column keys)
    $writer->addRow(Row::fromValues($headers));

    // Rows 2–5: preamble rows
    foreach (tiktokAllProductPreambleRows() as $preamble) {
        $writer->addRow(Row::fromValues(array_pad($preamble, count($headers), '')));
    }

    // Actual data rows (Row 6+)
    foreach ($dataRows as $dataRow) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $header): string => (string) ($dataRow[$header] ?? ''),
            $headers,
        )));
    }

    $writer->close();

    return $path;
}

function tiktokAllProductShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle('tiktok1', Platform::Tiktok, $location);
}

function tiktokAllProductCatalog(): void
{
    app(CreateProduct::class)->handle('รองเท้า Adda 41C23', [
        ['master_sku' => 'Adda.41C23.ดำ.25', 'name' => 'ดำ / EU:25', 'list_price' => Money::fromBaht('310')],
        ['master_sku' => 'Adda.41C23.ดำ.26', 'name' => 'ดำ / EU:26', 'list_price' => Money::fromBaht('310')],
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('creates Listing + ListingVariant (listed) and the resolver map from the real file structure', function () {
    $shop = tiktokAllProductShop();
    tiktokAllProductCatalog();

    $file = new UploadedFile(writeTiktokAllProductXlsx([
        [
            'product_id' => '1729611012959734534',
            'category' => 'รองเท้ากีฬา (806408)',
            'product_name' => 'รองเท้า Adda 41C23',
            'sku_id' => '1731603533798804230',
            'variation_value' => 'ดำ, 25',
            'price' => '310',
            'quantity' => '1',
            'seller_sku' => 'Adda.41C23.ดำ.25',
        ],
        [
            'product_id' => '1729611012959734534',
            'category' => 'รองเท้ากีฬา (806408)',
            'product_name' => 'รองเท้า Adda 41C23',
            'sku_id' => '1731603533798869766',
            'variation_value' => 'ดำ, 26',
            'price' => '310',
            'quantity' => '0',
            'seller_sku' => 'Adda.41C23.ดำ.26',
        ],
    ]), 'All product tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shop->id]);
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
        ->where('platform_sku', 'Adda.41C23.ดำ.25')
        ->firstOrFail();
    expect($lv1->variant()->firstOrFail()->master_sku)->toBe('Adda.41C23.ดำ.25');
});

it('holds a row whose seller_sku has no matching Master SKU — fail-loud, ADR 0005', function () {
    $shop = tiktokAllProductShop();
    tiktokAllProductCatalog();

    $file = new UploadedFile(writeTiktokAllProductXlsx([
        // This SKU exists in the catalog
        [
            'product_id' => '1729611012959734534',
            'seller_sku' => 'Adda.41C23.ดำ.25',
            'price' => '310',
            'quantity' => '1',
        ],
        // This SKU is NOT in the catalog
        [
            'product_id' => '1729611012959734534',
            'seller_sku' => 'SKU-NOT-IN-CATALOG',
            'price' => '310',
            'quantity' => '0',
        ],
    ]), 'All product tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    // The valid row still lands; the missing-SKU row is held
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SKU-NOT-IN-CATALOG')
        ->and(ListingVariant::query()->where('platform_sku', 'Adda.41C23.ดำ.25')->exists())->toBeTrue()
        ->and(ListingVariant::query()->where('platform_sku', 'SKU-NOT-IN-CATALOG')->exists())->toBeFalse();
});

it('holds a row where the seller_sku already resolves to a different Variant — SKU conflict, fail-loud', function () {
    $shop = tiktokAllProductShop();

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

    $file = new UploadedFile(writeTiktokAllProductXlsx([
        [
            'product_id' => '1729611012959734534',
            'seller_sku' => 'SKU-A',
            'price' => '100',
            'quantity' => '10',
        ],
    ]), 'All product tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SKU-A');
});

it('re-importing the same file is idempotent — no duplicates created', function () {
    $shop = tiktokAllProductShop();
    tiktokAllProductCatalog();

    $rows = [
        [
            'product_id' => '1729611012959734534',
            'seller_sku' => 'Adda.41C23.ดำ.25',
            'price' => '310',
            'quantity' => '1',
        ],
    ];

    foreach ([1, 2, 3] as $run) {
        $file = new UploadedFile(
            writeTiktokAllProductXlsx($rows),
            'All product tiktok.xlsx',
            null, null, true,
        );
        app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shop->id]);
    }

    expect(Listing::query()->where('shop_id', $shop->id)->count())->toBe(1)
        ->and(ListingVariant::query()->where('shop_id', $shop->id)->count())->toBe(1);
});

it('flips a draft ListingVariant to listed — Platform export is ground truth', function () {
    $shop = tiktokAllProductShop();
    tiktokAllProductCatalog();

    // Pre-create the Listing and ListingVariant in `draft` status (as the
    // Channel Upload Template fill engine would do for a TikTok listing)
    $product = Product::query()->where('name', 'รองเท้า Adda 41C23')->firstOrFail();
    $variant = Variant::query()->where('master_sku', 'Adda.41C23.ดำ.25')->firstOrFail();
    $listing = Listing::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'Adda.41C23.ดำ.25',
        'listing_status' => ListingStatus::Draft,
    ]);

    expect(ListingVariant::query()->where('platform_sku', 'Adda.41C23.ดำ.25')->firstOrFail()->listing_status)
        ->toBe(ListingStatus::Draft);

    // Import from Platform reality → must flip to listed
    $file = new UploadedFile(writeTiktokAllProductXlsx([
        [
            'product_id' => '1729611012959734534',
            'seller_sku' => 'Adda.41C23.ดำ.25',
            'price' => '310',
            'quantity' => '1',
        ],
    ]), 'All product tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and(ListingVariant::query()->where('platform_sku', 'Adda.41C23.ดำ.25')->firstOrFail()->listing_status)
        ->toBe(ListingStatus::Listed);
});

it('does not read another tenant\'s Variants — cross-tenant isolation', function () {
    // Tenant A (current context): no catalog
    $shopA = tiktokAllProductShop();

    // Tenant B: has the catalog
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $locationB = Location::query()->where('is_default', true)->firstOrFail();
    app(CreateShop::class)->handle('tiktok-b', Platform::Tiktok, $locationB);
    app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'SKU-B-ONLY', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    app(TenantContext::class)->forget();

    // Switch back to Tenant A, then import a file referencing Tenant B's SKU
    app(TenantContext::class)->set(Tenant::query()->where('name', 'A')->firstOrFail());
    Storage::fake('local');
    actingAs(User::factory()->create());

    $file = new UploadedFile(writeTiktokAllProductXlsx([
        [
            'product_id' => '9999999999999999999',
            'seller_sku' => 'SKU-B-ONLY',   // exists only in Tenant B
            'price' => '100',
            'quantity' => '5',
        ],
    ]), 'All product tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shopA->id]);
    $job->refresh();

    // Tenant A cannot see Tenant B's variants — must fail-loud with unmatched SKU
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(ListingVariant::query()->where('shop_id', $shopA->id)->count())->toBe(0);
});

it('preamble rows are not counted as import errors', function () {
    $shop = tiktokAllProductShop();
    tiktokAllProductCatalog();

    // A file with 4 preamble rows and one valid data row
    $file = new UploadedFile(writeTiktokAllProductXlsx([
        [
            'product_id' => '1729611012959734534',
            'seller_sku' => 'Adda.41C23.ดำ.25',
            'price' => '310',
            'quantity' => '1',
        ],
    ]), 'All product tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    // 4 preamble rows + 1 data row = 5 processed; 0 errors
    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0)
        ->and($job->processed_rows)->toBe(5);
});
