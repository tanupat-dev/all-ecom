<?php

namespace App\Jobs;

use App\Enums\ImportJobStatus;
use App\Imports\Importer;
use App\Imports\ImportJobAware;
use App\Imports\RowImportException;
use App\Models\ImportJob;
use App\Tenancy\RestoreTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

/**
 * The queued half of the central bulk-import pipeline (ROADMAP Phase 0):
 * streaming parse (constant RAM at any row count) → fail-loud row mapping
 * (ADR 0005: an unmappable row is held + reported, never defaulted) →
 * chunked upsert in a transaction per chunk → progress + final report on
 * the ImportJob row.
 */
class RunImportJob implements ShouldQueue
{
    use Queueable;

    public const CHUNK_SIZE = 500;

    /**
     * The error report is for a human to act on — cap it so a fully broken
     * file cannot grow the jsonb column without bound.
     */
    public const MAX_REPORTED_ERRORS = 1000;

    public function __construct(
        public readonly int $importJobId,
        public readonly int $tenantId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->tenantId)];
    }

    public function handle(): void
    {
        $importJob = ImportJob::query()->findOrFail($this->importJobId);
        $importJob->update(['status' => ImportJobStatus::Processing]);

        /** @var Importer $importer */
        $importer = app($importJob->importer);

        if ($importer instanceof ImportJobAware) {
            $importer->setImportJob($importJob);
        }

        $processed = 0;
        $errors = [];

        try {
            $chunk = [];

            foreach ($this->rows($importJob) as $rowNumber => $row) {
                try {
                    $chunk[] = $importer->mapRow($row, $rowNumber);
                } catch (RowImportException $e) {
                    if (count($errors) < self::MAX_REPORTED_ERRORS) {
                        $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
                    }
                    $processed++;

                    continue;
                }

                $processed++;

                if (count($chunk) >= self::CHUNK_SIZE) {
                    $this->flush($importer, $chunk, $importJob, $processed, $errors);
                    $chunk = [];
                }
            }

            $this->flush($importer, $chunk, $importJob, $processed, $errors);
        } catch (Throwable $e) {
            // A whole-file failure surfaces its reason to the seller too,
            // not only to the queue log.
            $importJob->update([
                'status' => ImportJobStatus::Failed,
                'errors' => [['row' => 0, 'message' => $e->getMessage()]],
            ]);

            throw $e;
        }

        $importJob->update([
            'status' => $errors === [] ? ImportJobStatus::Completed : ImportJobStatus::CompletedWithErrors,
            'processed_rows' => $processed,
            'error_rows' => count($errors),
            'errors' => $errors === [] ? null : $errors,
        ]);
    }

    /**
     * Streams header-keyed rows; the generator key is the 1-based
     * spreadsheet row number (data starts at row 2, after the header).
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function rows(ImportJob $importJob): iterable
    {
        $reader = $this->readerFor($importJob);
        $reader->open(Storage::disk('local')->path($importJob->stored_path));

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $cells = $row->toArray();

                    if ($headers === null) {
                        $headers = array_map(
                            static fn (mixed $cell): string => is_scalar($cell) || $cell === null
                                ? (string) $cell
                                : throw new RowImportException('A header cell is not text.'),
                            $cells,
                        );

                        continue;
                    }

                    yield $rowNumber => array_combine(
                        $headers,
                        array_pad(array_slice($cells, 0, count($headers)), count($headers), null),
                    );
                }

                // The pipeline contract is single-sheet files.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Every spreadsheet a platform exports (xlsx / csv — incl. an xlsx
     * misnamed .xls, which Shopee really ships) streams through the same
     * row contract. The CONTENT decides the reader, never the name or a
     * MIME sniff; a genuine Excel 97-2003 .xls is fail-loud with a clear
     * instruction instead of an obscure zip error (ADR 0005 spirit).
     */
    private function readerFor(ImportJob $importJob): CsvReader|XlsxReader
    {
        $magic = (string) file_get_contents(
            Storage::disk('local')->path($importJob->stored_path),
            length: 8,
        );

        if (str_starts_with($magic, "PK\x03\x04")) {
            return new XlsxReader;
        }

        if (str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException(
                'ระบบไม่รองรับ — ไฟล์ .xls แบบเก่า (Excel 97-2003): เปิดไฟล์แล้ว Save As เป็น .xlsx ก่อนนำเข้า'
            );
        }

        return strtolower(pathinfo($importJob->original_filename, PATHINFO_EXTENSION)) === 'csv'
            ? new CsvReader
            : new XlsxReader;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  list<array{row: int, message: string}>  $errors
     */
    private function flush(Importer $importer, array $chunk, ImportJob $importJob, int $processed, array $errors): void
    {
        if ($chunk !== []) {
            DB::transaction(fn () => $importer->upsertChunk($chunk));
        }

        $importJob->update([
            'processed_rows' => $processed,
            'error_rows' => count($errors),
        ]);
    }
}
