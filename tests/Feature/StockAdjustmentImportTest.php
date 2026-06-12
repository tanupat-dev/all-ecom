<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DefineBundle;
use App\Actions\Imports\StartImport;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Imports\StockAdjustmentImporter;
use App\Jobs\RunImportJob;
use App\Models\Location;
use App\Models\StockBalance;
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

function adjustmentVariant(string $sku = 'ADJ-1'): Variant
{
    return app(CreateProduct::class)
        ->handle("สินค้า {$sku}", [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
}

/**
 * @param  list<list<string>>  $rows  [master_sku, location, action, qty]
 */
function adjustmentXlsx(array $rows): UploadedFile
{
    $path = sys_get_temp_dir().'/adjustment-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['master_sku', 'location', 'action', 'qty']));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, 'adjustment.xlsx', null, null, true);
}

function adjustmentBalance(Variant $variant): ?StockBalance
{
    return StockBalance::query()->where('variant_id', $variant->id)->first();
}

it('applies receive / recount / damage / restore rows as ledger movements', function () {
    $variant = adjustmentVariant();

    $job = app(StartImport::class)->handle(adjustmentXlsx([
        ['ADJ-1', 'คลังหลัก', 'receive', '10'],
        ['ADJ-1', 'คลังหลัก', 'damage', '2'],
        ['ADJ-1', 'คลังหลัก', 'restore', '1'],
        ['ADJ-1', 'คลังหลัก', 'recount', '-3'],
    ]), StockAdjustmentImporter::class);

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and(adjustmentBalance($variant)?->on_hand)->toBe(6)   // 10 −2 +1 −3
        ->and(adjustmentBalance($variant)?->damaged)->toBe(1);  // +2 −1
});

it('holds and reports unmappable rows fail-loud while good rows apply', function () {
    $variant = adjustmentVariant();

    $job = app(StartImport::class)->handle(adjustmentXlsx([
        ['ADJ-1', 'คลังหลัก', 'receive', '10'],
        ['NO-SUCH-SKU', 'คลังหลัก', 'receive', '5'],
        ['ADJ-1', 'ไม่มีคลังนี้', 'receive', '5'],
        ['ADJ-1', 'คลังหลัก', 'shrinkage', '5'],
        ['ADJ-1', 'คลังหลัก', 'receive', 'มั่ว'],
    ]), StockAdjustmentImporter::class);

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(4)
        ->and(collect($job->errors)->pluck('row')->all())->toBe([3, 4, 5, 6])
        ->and(adjustmentBalance($variant)?->on_hand)->toBe(10);
});

it('does not double-apply rows when the job is retried', function () {
    $variant = adjustmentVariant();

    $job = app(StartImport::class)->handle(adjustmentXlsx([
        ['ADJ-1', 'คลังหลัก', 'receive', '10'],
    ]), StockAdjustmentImporter::class);

    // Simulate the queue retrying the whole file after a transient failure.
    (new RunImportJob($job->id, $job->tenant_id ?? 0))->handle();

    expect(adjustmentBalance($variant)?->on_hand)->toBe(10);
});

it('refuses adjusting a bundle row — bundles are virtual', function () {
    $bundle = adjustmentVariant('SET-1');
    app(DefineBundle::class)->handle($bundle, [[adjustmentVariant('COMP-1'), 1]]);

    $job = app(StartImport::class)->handle(adjustmentXlsx([
        ['SET-1', 'คลังหลัก', 'receive', '5'],
    ]), StockAdjustmentImporter::class);

    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->errors[0]['message'] ?? '')->toContain('Bundle');
});
