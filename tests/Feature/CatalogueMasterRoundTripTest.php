<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\ExportCatalogueMaster;
use App\Actions\Imports\StartImport;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Imports\CatalogueMasterImporter;
use App\Models\ImportJob;
use App\Models\Product;
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
    Storage::fake('local');
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
    actingAs(User::factory()->create());
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a catalogue-master xlsx from an array of rows.
 * Columns follow ExportCatalogueMaster::COLUMNS — the importer keys on these.
 *
 * @param  list<list<string|int|null>>  $rows
 */
function catalogueXlsx(array $rows): UploadedFile
{
    $path = sys_get_temp_dir().'/catalogue-master-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(ExportCatalogueMaster::COLUMNS));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, 'catalogue-master.xlsx', null, null, true);
}

function catalogueImport(UploadedFile $file): ImportJob
{
    return app(StartImport::class)->handle($file, CatalogueMasterImporter::class);
}

// ─────────────────────────────────────────────────────────────────────────────
// Export tests
// ─────────────────────────────────────────────────────────────────────────────

it('exports one row per Variant with the correct columns and data', function () {
    app(CreateProduct::class)->handle(
        name: 'เสื้อยืด',
        variants: [[
            'master_sku' => 'TS-RED-M',
            'name' => 'แดง / M',
            'list_price' => Money::fromBaht('199'),
            'package_weight_g' => 250,
            'package_width_mm' => 150,
            'package_length_mm' => 200,
            'package_height_mm' => 30,
        ]],
        meta: [
            'english_name' => 'T-Shirt',
            'description' => 'คอกลม ผ้าคอตตอน 100%',
            'brand' => 'FashionBrand',
        ],
    );

    $rows = app(ExportCatalogueMaster::class)->handle();

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($row['master_sku'])->toBe('TS-RED-M')
        ->and($row['product_name'])->toBe('เสื้อยืด')
        ->and($row['english_name'])->toBe('T-Shirt')
        ->and($row['description'])->toBe('คอกลม ผ้าคอตตอน 100%')
        ->and($row['brand'])->toBe('FashionBrand')
        ->and($row['variant_name'])->toBe('แดง / M')
        ->and($row['package_weight_g'])->toBe(250)
        ->and($row['package_width_mm'])->toBe(150)
        ->and($row['package_length_mm'])->toBe(200)
        ->and($row['package_height_mm'])->toBe(30);

    // list_price must NOT appear — price is excluded from this round-trip.
    expect($row)->not->toHaveKey('list_price');
});

it('exports one row per Variant — two Variants of the same Product appear as two rows', function () {
    app(CreateProduct::class)->handle(
        name: 'กระเป๋า',
        variants: [
            ['master_sku' => 'BAG-RED', 'name' => 'แดง', 'list_price' => Money::fromBaht('490')],
            ['master_sku' => 'BAG-BLUE', 'name' => 'น้ำเงิน', 'list_price' => Money::fromBaht('490')],
        ],
    );

    $rows = app(ExportCatalogueMaster::class)->handle();

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('master_sku')->sort()->values()->all())
        ->toBe(['BAG-BLUE', 'BAG-RED']);
});

it('exports null fields as null (empty cells in xlsx) for nullable listing fields', function () {
    app(CreateProduct::class)->handle(
        name: 'สินค้าทดสอบ',
        variants: [['master_sku' => 'NULL-EXPORT-1', 'list_price' => Money::fromBaht('99')]],
    );

    $rows = app(ExportCatalogueMaster::class)->handle();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['english_name'])->toBeNull()
        ->and($rows[0]['description'])->toBeNull()
        ->and($rows[0]['brand'])->toBeNull()
        ->and($rows[0]['variant_name'])->toBeNull()
        ->and($rows[0]['package_weight_g'])->toBeNull()
        ->and($rows[0]['package_width_mm'])->toBeNull()
        ->and($rows[0]['package_length_mm'])->toBeNull()
        ->and($rows[0]['package_height_mm'])->toBeNull();
});

it('export is scoped to the current tenant only', function () {
    app(CreateProduct::class)->handle('สินค้า A', [['master_sku' => 'SKU-A', 'list_price' => Money::fromBaht('100')]]);

    $other = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($other);
    app(CreateProduct::class)->handle('สินค้า B', [['master_sku' => 'SKU-B', 'list_price' => Money::fromBaht('100')]]);

    // Back to tenant A — export must show only SKU-A.
    $tenantA = Tenant::query()->where('name', 'A')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    $rows = app(ExportCatalogueMaster::class)->handle();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['master_sku'])->toBe('SKU-A');
});

// ─────────────────────────────────────────────────────────────────────────────
// Import (update) tests — the round-trip core
// ─────────────────────────────────────────────────────────────────────────────

it('updates all editable fields on re-import', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'เสื้อยืดเดิม',
        variants: [[
            'master_sku' => 'TS-EDIT-1',
            'name' => 'แดง',
            'list_price' => Money::fromBaht('199'),
        ]],
    );
    $variant = $product->variants->firstOrFail();

    $job = catalogueImport(catalogueXlsx([[
        'TS-EDIT-1',       // master_sku
        'เสื้อยืดใหม่',    // product_name
        'New T-Shirt',     // english_name
        'สีสด ผ้าดี',      // description
        'BrandX',          // brand
        'น้ำเงิน',         // variant_name
        '300',             // package_weight_g
        '180',             // package_width_mm
        '220',             // package_length_mm
        '40',              // package_height_mm
    ]]));

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed);

    $product->refresh();
    $variant->refresh();

    expect($product->name)->toBe('เสื้อยืดใหม่')
        ->and($product->english_name)->toBe('New T-Shirt')
        ->and($product->description)->toBe('สีสด ผ้าดี')
        ->and($product->brand)->toBe('BrandX')
        ->and($variant->name)->toBe('น้ำเงิน')
        ->and($variant->package_weight_g)->toBe(300)
        ->and($variant->package_width_mm)->toBe(180)
        ->and($variant->package_length_mm)->toBe(220)
        ->and($variant->package_height_mm)->toBe(40);
});

it('list_price is NOT changed by a catalogue-master import (price excluded from round-trip)', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'สินค้าราคา',
        variants: [['master_sku' => 'PRICE-GUARD-1', 'list_price' => Money::fromBaht('499')]],
    );
    $variant = $product->variants->firstOrFail();

    catalogueImport(catalogueXlsx([[
        'PRICE-GUARD-1', 'สินค้าราคา', null, null, null, null, null, null, null, null,
    ]]));

    $variant->refresh();
    // Price must remain unchanged regardless of import.
    expect($variant->list_price?->satang)->toBe(49900);
});

it('untouched variants are not modified when only one variant in a multi-variant product is re-imported', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'กางเกง',
        variants: [
            ['master_sku' => 'PANT-RED', 'name' => 'แดง', 'list_price' => Money::fromBaht('299'), 'package_weight_g' => 400],
            ['master_sku' => 'PANT-BLUE', 'name' => 'น้ำเงิน', 'list_price' => Money::fromBaht('299'), 'package_weight_g' => 410],
        ],
    );

    $red = $product->variants->where('master_sku', 'PANT-RED')->firstOrFail();
    $blue = $product->variants->where('master_sku', 'PANT-BLUE')->firstOrFail();

    // Only re-import PANT-RED with updated weight; PANT-BLUE row is omitted.
    catalogueImport(catalogueXlsx([[
        'PANT-RED', 'กางเกง', null, null, null, 'แดง-ใหม่', '500', null, null, null,
    ]]));

    $red->refresh();
    $blue->refresh();

    expect($red->package_weight_g)->toBe(500)
        ->and($red->name)->toBe('แดง-ใหม่')
        // PANT-BLUE must be untouched — it wasn't in the import file.
        ->and($blue->package_weight_g)->toBe(410)
        ->and($blue->name)->toBe('น้ำเงิน');
});

// ─────────────────────────────────────────────────────────────────────────────
// Blank-cell semantics (WYSIWYG: blank = null)
// ─────────────────────────────────────────────────────────────────────────────

it('sets nullable fields to null when the cell is blank (WYSIWYG blank = null)', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'สินค้าเต็ม',
        variants: [[
            'master_sku' => 'NULL-TEST-2',
            'name' => 'VariantName',
            'list_price' => Money::fromBaht('99'),
            'package_weight_g' => 500,
        ]],
        meta: [
            'english_name' => 'Full Product',
            'brand' => 'SomeBrand',
        ],
    );
    $variant = $product->variants->firstOrFail();

    // All nullable fields blank → they should all become null.
    catalogueImport(catalogueXlsx([[
        'NULL-TEST-2', 'สินค้าเต็ม', '', '', '', '', '', '', '', '',
    ]]));

    $product->refresh();
    $variant->refresh();

    expect($product->english_name)->toBeNull()
        ->and($product->brand)->toBeNull()
        ->and($variant->name)->toBeNull()
        ->and($variant->package_weight_g)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Fail-loud cases (ADR 0005)
// ─────────────────────────────────────────────────────────────────────────────

it('holds an unknown master_sku and surfaces it as an error (ADR 0005)', function () {
    app(CreateProduct::class)->handle('สินค้า', [['master_sku' => 'EXISTS-1', 'list_price' => Money::fromBaht('99')]]);

    $job = catalogueImport(catalogueXlsx([
        ['EXISTS-1', 'สินค้า', null, null, null, null, null, null, null, null],
        ['NO-SUCH-SKU', 'สินค้า', null, null, null, null, null, null, null, null],
    ]));

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and($job->errors[0]['message'] ?? '')->toContain('NO-SUCH-SKU');
});

it('holds a row with an empty master_sku — fail-loud (ADR 0005)', function () {
    $job = catalogueImport(catalogueXlsx([[
        '', 'สินค้า', null, null, null, null, null, null, null, null,
    ]]));

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1);
});

it('holds a row with a blank product_name — product_name is required', function () {
    app(CreateProduct::class)->handle('สินค้า', [['master_sku' => 'NO-NAME-1', 'list_price' => Money::fromBaht('99')]]);

    $job = catalogueImport(catalogueXlsx([[
        'NO-NAME-1', '', null, null, null, null, null, null, null, null,
    ]]));

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and($job->errors[0]['message'] ?? '')->toContain('product_name');
});

it('holds a row with a non-integer dimension value — fail-loud', function () {
    app(CreateProduct::class)->handle('สินค้า', [['master_sku' => 'DIM-ERR-1', 'list_price' => Money::fromBaht('99')]]);

    $job = catalogueImport(catalogueXlsx([[
        'DIM-ERR-1', 'สินค้า', null, null, null, null, 'ไม่ใช่เลข', null, null, null,
    ]]));

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->errors[0]['message'] ?? '')->toContain('package_weight_g');
});

// ─────────────────────────────────────────────────────────────────────────────
// Idempotency
// ─────────────────────────────────────────────────────────────────────────────

it('is idempotent — re-importing the same file twice produces the same result', function () {
    app(CreateProduct::class)->handle(
        name: 'สินค้า Idempotent',
        variants: [['master_sku' => 'IDEMP-1', 'list_price' => Money::fromBaht('199')]],
        meta: ['english_name' => 'Old Name', 'brand' => 'Old Brand'],
    );

    $file = catalogueXlsx([[
        'IDEMP-1', 'สินค้า Idempotent', 'New Name', 'desc', 'New Brand', null, '300', null, null, null,
    ]]);

    // First import.
    $job1 = app(StartImport::class)->handle($file, CatalogueMasterImporter::class);
    expect($job1->refresh()->status)->toBe(ImportJobStatus::Completed);

    $product = Product::query()->whereHas('variants', fn ($q) => $q->where('master_sku', 'IDEMP-1'))->firstOrFail();
    $variant = $product->variants->firstOrFail();

    expect($product->english_name)->toBe('New Name')
        ->and($product->brand)->toBe('New Brand')
        ->and($variant->package_weight_g)->toBe(300);

    // Second import of the same file — simulate a retry.
    $file2 = catalogueXlsx([[
        'IDEMP-1', 'สินค้า Idempotent', 'New Name', 'desc', 'New Brand', null, '300', null, null, null,
    ]]);
    $job2 = app(StartImport::class)->handle($file2, CatalogueMasterImporter::class);
    expect($job2->refresh()->status)->toBe(ImportJobStatus::Completed);

    $product->refresh();
    $variant->refresh();

    expect($product->english_name)->toBe('New Name')
        ->and($product->brand)->toBe('New Brand')
        ->and($variant->package_weight_g)->toBe(300);
});

// ─────────────────────────────────────────────────────────────────────────────
// Cross-tenant isolation (ADR 0011)
// ─────────────────────────────────────────────────────────────────────────────

it('import cannot update another tenant\'s product — unknown SKU from tenant perspective', function () {
    // Tenant A creates a product.
    $tenantA = Tenant::query()->where('name', 'A')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    $productA = app(CreateProduct::class)->handle(
        'สินค้า Tenant A',
        [['master_sku' => 'SKU-TENANT-A', 'list_price' => Money::fromBaht('100')]],
        meta: ['english_name' => 'Original'],
    );

    // Tenant B tries to import a row with SKU-TENANT-A (which exists only in
    // tenant A). The BelongsToTenant global scope means B cannot see A's
    // variant, so the row is fail-loud "unknown SKU".
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);

    $job = catalogueImport(catalogueXlsx([[
        'SKU-TENANT-A', 'สินค้า Hack', null, null, null, null, null, null, null, null,
    ]]));

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->errors[0]['message'] ?? '')->toContain('SKU-TENANT-A');

    // Verify the original product in tenant A is untouched.
    app(TenantContext::class)->set($tenantA);
    $productA->refresh();

    expect($productA->name)->toBe('สินค้า Tenant A')
        ->and($productA->english_name)->toBe('Original');
});

it('mixed good-and-bad rows: good rows update, bad rows are held', function () {
    app(CreateProduct::class)->handle('สินค้า 1', [['master_sku' => 'GOOD-1', 'list_price' => Money::fromBaht('99')]]);
    app(CreateProduct::class)->handle('สินค้า 2', [['master_sku' => 'GOOD-2', 'list_price' => Money::fromBaht('99')]]);

    $job = catalogueImport(catalogueXlsx([
        ['GOOD-1', 'อัปเดต 1', null, null, 'Brand1', null, null, null, null, null],
        ['MISSING-SKU', 'จะ error', null, null, null, null, null, null, null, null],
        ['GOOD-2', 'อัปเดต 2', null, null, 'Brand2', null, null, null, null, null],
    ]));

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1);

    $p1 = Product::query()->whereHas('variants', fn ($q) => $q->where('master_sku', 'GOOD-1'))->firstOrFail();
    $p2 = Product::query()->whereHas('variants', fn ($q) => $q->where('master_sku', 'GOOD-2'))->firstOrFail();

    expect($p1->name)->toBe('อัปเดต 1')
        ->and($p1->brand)->toBe('Brand1')
        ->and($p2->name)->toBe('อัปเดต 2')
        ->and($p2->brand)->toBe('Brand2');
});

// ─────────────────────────────────────────────────────────────────────────────
// Filament UI — smoke test for the header actions
// ─────────────────────────────────────────────────────────────────────────────

it('ListProducts page renders the export and import header actions for an Admin user', function () {
    actingAs(User::factory()->create()->assignRole('Admin'));

    Livewire\Livewire::test(ListProducts::class)
        ->assertActionExists('exportCatalogueMaster')
        ->assertActionExists('importCatalogueMaster');
});
