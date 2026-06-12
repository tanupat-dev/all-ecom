<?php

namespace App\Actions\Imports;

use App\Enums\ImportJobStatus;
use App\Imports\Importer;
use App\Jobs\RunImportJob;
use App\Models\ImportJob;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use LogicException;

/**
 * Entry point of the central bulk-import pipeline (ROADMAP Phase 0):
 * store the upload privately, create the ImportJob status row, queue the
 * streaming run. Every Excel feature starts an import through this.
 */
class StartImport
{
    /**
     * The importer class name is persisted and re-instantiated by the queued
     * run, so it is validated here at runtime, fail-loud — not only by the
     * type system.
     *
     * @param  class-string  $importerClass
     */
    public function handle(UploadedFile $file, string $importerClass): ImportJob
    {
        if (! is_subclass_of($importerClass, Importer::class)) {
            throw new InvalidArgumentException("[{$importerClass}] must implement ".Importer::class);
        }

        $tenant = app(TenantContext::class)->current()
            ?? throw new LogicException('An import needs a tenant context.');

        $storedPath = $file->store("imports/{$tenant->id}")
            ?: throw new LogicException('Failed to store the uploaded import file.');

        $importJob = ImportJob::query()->create([
            'importer' => $importerClass,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => ImportJobStatus::Pending,
        ]);

        RunImportJob::dispatch($importJob->id, $tenant->id);

        return $importJob;
    }
}
