<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Imports\ShopeeAllProductImporter;
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
 * The 10 machine-key column headers from `ref doc/shopee/All product
 * shopee.xlsx` Row 1 (ADR 0019: anchored on machine-key row).
 *
 * @return list<string>
 */
function allProductMachineKeys(): array
{
    return [
        'et_title_product_id',
        'et_title_product_name',
        'et_title_variation_id',
        'et_title_variation_name',
        'et_title_parent_sku',
        'et_title_variation_sku',
        'et_title_variation_price',
        'ps_gtin_code',
        'et_title_variation_stock',
        'ps_minimum_purchase_quantity',
    ];
}

/**
 * The 4 preamble rows Shopee inserts before actual data (verified from the
 * reference file):
 *  Row 2 — metadata / search_condition JSON
 *  Row 3 — Thai column labels
 *  Row 4 — required-field markers
 *  Row 5 — instruction text
 *
 * @return list<list<string>>
 */
function allProductPreambleRows(): array
{
    return [
        // Row 2: metadata
        ['sales_info', '35b74571cd927608dc4cc2b998b916cb', '0', '217521245', '{"search_condition":{"product_status":[1]}}', '', '', '', '', ''],
        // Row 3: Thai header labels
        ['รหัสสินค้า', 'ชื่อสินค้า', 'รหัสตัวเลือกสินค้า', 'ชื่อตัวเลือกสินค้า', 'Parent SKU', 'เลข SKU', 'ราคา', 'GTIN', 'คลัง', 'จำนวนการซื้อขั้นต่ำ'],
        // Row 4: required markers
        ['', '', '', '', '', '', 'จำเป็นต้องกรอก', '', 'จำเป็นต้องกรอก', ''],
        // Row 5: instructions
        ['', '', '', '', '', '', 'ระบุราคาสินค้าระหว่าง 1-500000 ราคาของตัวเลือกสินค้าจะต้องต่างกันไม่เกิน 5 เท่า', '', '', 'จำนวนการซื้อขั้นต่ำ หมายถึงจำนวนสินค้า...'],
    ];
}

/**
 * Write a Shopee "All product" xlsx that mirrors the real file's structure:
 * Row 1 = machine-key headers, Rows 2–5 = preamble, Row 6+ = data.
 *
 * @param  list<array<string, string>>  $dataRows  machine_key => value
 */
function writeShopeeAllProductXlsx(array $dataRows): string
{
    $keys = allProductMachineKeys();
    $path = sys_get_temp_dir().'/shopee-allproduct-test-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    // Row 1: machine-key headers (the pipeline's column keys)
    $writer->addRow(Row::fromValues($keys));

    // Rows 2–5: preamble rows
    foreach (allProductPreambleRows() as $preamble) {
        $writer->addRow(Row::fromValues(array_pad($preamble, count($keys), '')));
    }

    // Actual data rows (Row 6+)
    foreach ($dataRows as $dataRow) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $key): string => (string) ($dataRow[$key] ?? ''),
            $keys,
        )));
    }

    $writer->close();

    return $path;
}

function allProductShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle('shopee1', Platform::Shopee, $location);
}

function allProductCatalog(): void
{
    app(CreateProduct::class)->handle('เสื้อยืด Deblu', [
        ['master_sku' => 'Deblu.L9217-1.เบจ.39', 'name' => 'เบจ / EU:39', 'list_price' => Money::fromBaht('495')],
        ['master_sku' => 'Deblu.L9217-1.เบจ.41', 'name' => 'เบจ / EU:41', 'list_price' => Money::fromBaht('495')],
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('creates Listing + ListingVariant (listed) and the resolver map from the real file structure', function () {
    $shop = allProductShop();
    allProductCatalog();

    $file = new UploadedFile(writeShopeeAllProductXlsx([
        [
            'et_title_product_id' => '27224950210',
            'et_title_product_name' => 'Deblu รองเท้า L9217-1',
            'et_title_variation_id' => '233764026830',
            'et_title_variation_name' => 'เบจ,EU: 39',
            'et_title_variation_sku' => 'Deblu.L9217-1.เบจ.39',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
        [
            'et_title_product_id' => '27224950210',
            'et_title_product_name' => 'Deblu รองเท้า L9217-1',
            'et_title_variation_id' => '247570949389',
            'et_title_variation_name' => 'เบจ,EU: 41',
            'et_title_variation_sku' => 'Deblu.L9217-1.เบจ.41',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
    ]), 'All product shopee.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shop->id]);
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
        ->where('platform_sku', 'Deblu.L9217-1.เบจ.39')
        ->firstOrFail();
    expect($lv1->variant()->firstOrFail()->master_sku)->toBe('Deblu.L9217-1.เบจ.39');
});

it('holds a row whose platform SKU has no matching Master SKU — fail-loud, ADR 0005', function () {
    $shop = allProductShop();
    allProductCatalog();

    $file = new UploadedFile(writeShopeeAllProductXlsx([
        // This SKU exists in the catalog
        [
            'et_title_product_id' => '27224950210',
            'et_title_variation_sku' => 'Deblu.L9217-1.เบจ.39',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
        // This SKU is NOT in the catalog
        [
            'et_title_product_id' => '27224950210',
            'et_title_variation_sku' => 'SKU-NOT-IN-CATALOG',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
    ]), 'All product shopee.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    // The valid row still lands; the missing-SKU row is held
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SKU-NOT-IN-CATALOG')
        ->and(ListingVariant::query()->where('platform_sku', 'Deblu.L9217-1.เบจ.39')->exists())->toBeTrue()
        ->and(ListingVariant::query()->where('platform_sku', 'SKU-NOT-IN-CATALOG')->exists())->toBeFalse();
});

it('holds a row where the platform SKU already resolves to a different Variant — SKU conflict, fail-loud', function () {
    $shop = allProductShop();

    // Two products with different variants
    app(CreateProduct::class)->handle('สินค้า A', [
        ['master_sku' => 'SKU-A', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'SKU-B', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);

    // Manually create a conflicting mapping: SKU-A → variant of product B
    $productB = app(CreateProduct::class)->handle('สินค้า B-dupe', [
        ['master_sku' => 'SKU-B-dupe', 'name' => null, 'list_price' => Money::fromBaht('200')],
    ]);

    // Plant an existing ListingVariant that maps 'SKU-A' to SKU-B-dupe's variant
    // (forcing a conflict when the import tries to map SKU-A → SKU-A's own variant)
    $conflictProduct = app(CreateProduct::class)->handle('Conflict Product', [
        ['master_sku' => 'CONFLICT-ORIGINAL', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    $conflictListing = Listing::query()->create([
        'shop_id' => $shop->id,
        'product_id' => $conflictProduct->id,
    ]);
    $conflictVariant = $conflictProduct->variants()->firstOrFail();

    // Manually plant: platform_sku = 'SKU-A' → conflictVariant (NOT SKU-A's own variant)
    $skuAVariant = Variant::query()->where('master_sku', 'SKU-A')->firstOrFail();
    $conflictListing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $conflictVariant->id,
        'platform_sku' => 'SKU-A',  // same platform_sku as SKU-A but different variant!
        'listing_status' => ListingStatus::Listed,
    ]);

    $file = new UploadedFile(writeShopeeAllProductXlsx([
        [
            'et_title_product_id' => '11111111',
            'et_title_variation_sku' => 'SKU-A',
            'et_title_variation_price' => '100',
            'et_title_variation_stock' => '10',
        ],
    ]), 'All product shopee.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SKU-A');
});

it('re-importing the same file is idempotent — no duplicates created', function () {
    $shop = allProductShop();
    allProductCatalog();

    $rows = [
        [
            'et_title_product_id' => '27224950210',
            'et_title_variation_sku' => 'Deblu.L9217-1.เบจ.39',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
    ];

    foreach ([1, 2, 3] as $run) {
        $file = new UploadedFile(
            writeShopeeAllProductXlsx($rows),
            'All product shopee.xlsx',
            null, null, true,
        );
        app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shop->id]);
    }

    expect(Listing::query()->where('shop_id', $shop->id)->count())->toBe(1)
        ->and(ListingVariant::query()->where('shop_id', $shop->id)->count())->toBe(1);
});

it('flips a draft ListingVariant to listed — Platform export is ground truth', function () {
    $shop = allProductShop();
    allProductCatalog();

    // Pre-create the Listing and ListingVariant in `draft` status (as the
    // Channel Upload Template fill engine would do for Issue #57–#59)
    $product = Product::query()->where('name', 'เสื้อยืด Deblu')->firstOrFail();
    $variant = Variant::query()->where('master_sku', 'Deblu.L9217-1.เบจ.39')->firstOrFail();
    $listing = Listing::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id]);
    $listing->variants()->create([
        'shop_id' => $shop->id,
        'variant_id' => $variant->id,
        'platform_sku' => 'Deblu.L9217-1.เบจ.39',
        'listing_status' => ListingStatus::Draft,
    ]);

    expect(ListingVariant::query()->where('platform_sku', 'Deblu.L9217-1.เบจ.39')->firstOrFail()->listing_status)
        ->toBe(ListingStatus::Draft);

    // Import from Platform reality → must flip to listed
    $file = new UploadedFile(writeShopeeAllProductXlsx([
        [
            'et_title_product_id' => '27224950210',
            'et_title_variation_sku' => 'Deblu.L9217-1.เบจ.39',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
    ]), 'All product shopee.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and(ListingVariant::query()->where('platform_sku', 'Deblu.L9217-1.เบจ.39')->firstOrFail()->listing_status)
        ->toBe(ListingStatus::Listed);
});

it('does not read another tenant\'s Variants — cross-tenant isolation', function () {
    // Tenant A (current context): no catalog
    $shopA = allProductShop();

    // Tenant B: has the catalog
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $locationB = Location::query()->where('is_default', true)->firstOrFail();
    $shopB = app(CreateShop::class)->handle('shopee-b', Platform::Shopee, $locationB);
    app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'SKU-B-ONLY', 'name' => null, 'list_price' => Money::fromBaht('100')],
    ]);
    app(TenantContext::class)->forget();

    // Switch back to Tenant A, then import a file referencing Tenant B's SKU
    app(TenantContext::class)->set(Tenant::query()->where('name', 'A')->firstOrFail());
    Storage::fake('local');
    actingAs(User::factory()->create());

    $file = new UploadedFile(writeShopeeAllProductXlsx([
        [
            'et_title_product_id' => '99999999',
            'et_title_variation_sku' => 'SKU-B-ONLY',   // exists only in Tenant B
            'et_title_variation_price' => '100',
            'et_title_variation_stock' => '5',
        ],
    ]), 'All product shopee.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shopA->id]);
    $job->refresh();

    // Tenant A cannot see Tenant B's variants — must fail-loud with unmatched SKU
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(ListingVariant::query()->where('shop_id', $shopA->id)->count())->toBe(0);
});

it('preamble rows are not counted as import errors', function () {
    $shop = allProductShop();
    allProductCatalog();

    // A file with only preamble rows and one valid data row
    $file = new UploadedFile(writeShopeeAllProductXlsx([
        [
            'et_title_product_id' => '27224950210',
            'et_title_variation_sku' => 'Deblu.L9217-1.เบจ.39',
            'et_title_variation_price' => '495',
            'et_title_variation_stock' => '50',
        ],
    ]), 'All product shopee.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAllProductImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    // 4 preamble rows + 1 data row = 5 processed; 0 errors
    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0)
        ->and($job->processed_rows)->toBe(5);
});
