<?php

use App\Actions\Imports\StartImport;
use App\Enums\ImportJobStatus;
use App\Imports\Importer;
use App\Imports\RowImportException;
use App\Jobs\RunImportJob;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    Storage::fake('local');

    $tenant = Tenant::query()->create(['name' => 'A']);
    app(TenantContext::class)->set($tenant);
    actingAs(User::factory()->create());
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * Collects what the pipeline hands it instead of writing to a real table —
 * the pipeline's behaviour is what's under test, not a domain upsert.
 */
class FakeImporter implements Importer
{
    /** @var list<list<array<string, mixed>>> */
    public static array $chunks = [];

    public function mapRow(array $row, int $rowNumber): array
    {
        $qty = $row['qty'] ?? null;

        if (! is_numeric($qty)) {
            throw new RowImportException('Unmapped qty value ['.(is_scalar($qty) ? $qty : gettype($qty)).']');
        }

        return ['sku' => $row['sku'], 'qty' => (int) $qty];
    }

    public function upsertChunk(array $chunk): void
    {
        self::$chunks[] = $chunk;
    }
}

/**
 * Writes a real xlsx with a header row + the given rows.
 *
 * @param  list<list<string>>  $rows
 */
function writeTestXlsx(array $rows): string
{
    $path = sys_get_temp_dir().'/import-test-'.uniqid().'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['sku', 'qty']));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

it('stores the upload, creates a pending ImportJob, and queues the run', function () {
    Queue::fake();

    $file = new UploadedFile(writeTestXlsx([['SKU-1', '5']]), 'stock.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeImporter::class);

    expect($job->status)->toBe(ImportJobStatus::Pending)
        ->and($job->original_filename)->toBe('stock.xlsx')
        ->and(Storage::disk('local')->exists($job->stored_path))->toBeTrue();

    Queue::assertPushed(RunImportJob::class);
});

it('streams, chunks, and completes a clean file', function () {
    FakeImporter::$chunks = [];
    $file = new UploadedFile(writeTestXlsx([['SKU-1', '5'], ['SKU-2', '7']]), 'stock.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeImporter::class);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->processed_rows)->toBe(2)
        ->and($job->error_rows)->toBe(0)
        ->and($job->errors)->toBeNull()
        ->and(FakeImporter::$chunks)->toBe([[
            ['sku' => 'SKU-1', 'qty' => 5],
            ['sku' => 'SKU-2', 'qty' => 7],
        ]]);
});

it('holds and reports an unmappable row, fail-loud, while good rows land', function () {
    FakeImporter::$chunks = [];
    $file = new UploadedFile(
        writeTestXlsx([['SKU-1', '5'], ['SKU-2', 'broken'], ['SKU-3', '7']]),
        'stock.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, FakeImporter::class);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->processed_rows)->toBe(3)
        ->and($job->error_rows)->toBe(1)
        ->and($job->errors)->toBe([['row' => 3, 'message' => 'Unmapped qty value [broken]']])
        ->and(FakeImporter::$chunks)->toBe([[
            ['sku' => 'SKU-1', 'qty' => 5],
            ['sku' => 'SKU-3', 'qty' => 7],
        ]]);
});

it('streams an xlsx even when it is misnamed .xls — the content decides, not the name', function () {
    FakeImporter::$chunks = [];
    $file = new UploadedFile(writeTestXlsx([['SKU-1', '5']]), 'Order.return_refund.xls', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeImporter::class);

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and(FakeImporter::$chunks)->toBe([[['sku' => 'SKU-1', 'qty' => 5]]]);
});

it('fails loud with a clear message on a legacy Excel 97-2003 .xls', function () {
    Queue::fake();
    $path = sys_get_temp_dir().'/legacy-'.uniqid().'.xls';
    // The OLE2 compound-document magic that opens every BIFF .xls.
    file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 64));
    $file = new UploadedFile($path, 'orders.xls', null, null, true);
    $job = app(StartImport::class)->handle($file, FakeImporter::class);

    try {
        (new RunImportJob($job->id, $job->tenant_id ?? 0))->handle();
    } catch (Throwable) {
        // Rethrown so the queue records the failure.
    }

    expect($job->refresh()->status)->toBe(ImportJobStatus::Failed)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('Save As');
});

it('marks the ImportJob failed when the file cannot be processed at all', function () {
    Queue::fake();
    $file = new UploadedFile(tempnam(sys_get_temp_dir(), 'not-xlsx'), 'stock.xlsx', null, null, true);
    $job = app(StartImport::class)->handle($file, FakeImporter::class);

    $threw = false;

    try {
        (new RunImportJob($job->id, $job->tenant_id ?? 0))->handle();
    } catch (Throwable) {
        // The run rethrows (so the queue records the failure) after
        // stamping the status.
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and($job->refresh()->status)->toBe(ImportJobStatus::Failed);
});

it('rejects a class that is not an Importer', function () {
    $file = new UploadedFile(writeTestXlsx([]), 'stock.xlsx', null, null, true);

    app(StartImport::class)->handle($file, stdClass::class);
})->throws(InvalidArgumentException::class, 'must implement');

it('passes the cross-tenant isolation harness', function () {
    Queue::fake();

    assertTenantIsolation(function (): ImportJob {
        $file = new UploadedFile(writeTestXlsx([]), 'stock.xlsx', null, null, true);

        return app(StartImport::class)->handle($file, FakeImporter::class);
    });
});
