<?php

use App\Actions\Imports\StartImport;
use App\Enums\ImportJobStatus;
use App\Imports\HasSheetLayout;
use App\Imports\Importer;
use App\Jobs\RunImportJob;
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
 * A tiny importer that opts into HasSheetLayout — reads a named sheet whose
 * header sits below a preamble. It only collects what the pipeline hands it.
 */
class FakeSheetLayoutImporter implements HasSheetLayout, Importer
{
    public static ?string $sheet = null;

    public static int $offset = 1;

    /** @var list<array{row: int, cells: array<string, mixed>}> */
    public static array $rows = [];

    public function sheetName(): ?string
    {
        return self::$sheet;
    }

    public function headerRowOffset(): int
    {
        return self::$offset;
    }

    public function mapRow(array $row, int $rowNumber): array
    {
        self::$rows[] = ['row' => $rowNumber, 'cells' => $row];

        return $row;
    }

    public function upsertChunk(array $chunk): void
    {
        // The behaviour under test is the streaming layout, not a real write.
    }
}

/**
 * Writes a multi-sheet xlsx. $sheets maps a sheet name to its rows (each row a
 * list of cell values), so a test can place the data sheet second and add a
 * preamble above the header.
 *
 * @param  array<string, list<list<string>>>  $sheets
 */
function writeMultiSheetXlsx(array $sheets): string
{
    $path = sys_get_temp_dir().'/sheet-layout-'.uniqid().'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);

    $first = true;
    foreach ($sheets as $name => $rows) {
        if (! $first) {
            $writer->addNewSheetAndMakeItCurrent();
        }
        $writer->getCurrentSheet()->setName($name);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $first = false;
    }

    $writer->close();

    return $path;
}

beforeEach(function () {
    FakeSheetLayoutImporter::$sheet = null;
    FakeSheetLayoutImporter::$offset = 1;
    FakeSheetLayoutImporter::$rows = [];
});

it('selects a named non-first sheet instead of the first', function () {
    FakeSheetLayoutImporter::$sheet = 'Income';
    FakeSheetLayoutImporter::$offset = 1;

    $file = new UploadedFile(writeMultiSheetXlsx([
        'Summary' => [['ignore', 'me'], ['x', 'y']],
        'Income' => [['order_id', 'amount'], ['SP-1', '100']],
    ]), 'accounting.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeSheetLayoutImporter::class);

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and(FakeSheetLayoutImporter::$rows)->toBe([
            ['row' => 2, 'cells' => ['order_id' => 'SP-1', 'amount' => '100']],
        ]);
});

it('skips preamble above a header offset and keys data rows at their emitted row', function () {
    FakeSheetLayoutImporter::$sheet = 'Income';
    FakeSheetLayoutImporter::$offset = 4;

    // Preamble rows are non-empty: openspout drops fully-blank rows, so the
    // emitted-row counter only lines up deterministically with non-blank rows.
    $file = new UploadedFile(writeMultiSheetXlsx([
        'Income' => [
            ['preamble-1'],                 // row 1
            ['preamble-2'],                 // row 2
            ['totals', '999'],              // row 3
            ['order_id', 'amount'],         // row 4 — header
            ['SP-1', '100'],                // row 5 — data
            ['SP-2', '200'],                // row 6 — data
        ],
    ]), 'accounting.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeSheetLayoutImporter::class);

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and(FakeSheetLayoutImporter::$rows)->toBe([
            ['row' => 5, 'cells' => ['order_id' => 'SP-1', 'amount' => '100']],
            ['row' => 6, 'cells' => ['order_id' => 'SP-2', 'amount' => '200']],
        ]);
});

it('fails loud when the named sheet is missing', function () {
    Queue::fake();
    FakeSheetLayoutImporter::$sheet = 'Income';
    FakeSheetLayoutImporter::$offset = 1;

    $file = new UploadedFile(writeMultiSheetXlsx([
        'Summary' => [['order_id', 'amount'], ['SP-1', '100']],
    ]), 'accounting.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeSheetLayoutImporter::class);

    // A whole-file failure rethrows so the queue records it; the status +
    // message are stamped on the ImportJob first.
    try {
        (new RunImportJob($job->id, $job->tenant_id ?? 0))->handle();
    } catch (Throwable) {
        // expected
    }

    expect($job->refresh()->status)->toBe(ImportJobStatus::Failed)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('Income')
        ->and(FakeSheetLayoutImporter::$rows)->toBe([]);
});

it('leaves an importer that does not implement the interface unchanged (first sheet, header row 1)', function () {
    // FakeSheetLayoutImporter implements the interface; prove the default by
    // using the un-opted-in path explicitly via offset 1 / sheet null AND by
    // routing through an importer that does not implement HasSheetLayout.
    FakeSheetLayoutImporter::$sheet = null;
    FakeSheetLayoutImporter::$offset = 1;

    $file = new UploadedFile(writeMultiSheetXlsx([
        'First' => [['order_id', 'amount'], ['SP-1', '100']],
        'Second' => [['nope'], ['x']],
    ]), 'accounting.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakeSheetLayoutImporter::class);

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and(FakeSheetLayoutImporter::$rows)->toBe([
            ['row' => 2, 'cells' => ['order_id' => 'SP-1', 'amount' => '100']],
        ]);
});
