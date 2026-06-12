<?php

namespace App\Actions\Imports;

use App\Enums\ImportJobStatus;
use App\Imports\ChannelTemplate\TemplateFillImporter;
use App\Jobs\RunTemplateFillJob;
use App\Models\ImportJob;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use LogicException;

/**
 * Entry point for the Channel Upload Template fill pipeline (ADR 0019,
 * Phase 9 B). Mirrors StartImport but dispatches RunTemplateFillJob
 * instead of RunImportJob because the fill operation:
 *
 *  - iterates over Variants from context (not spreadsheet rows),
 *  - writes an output file (the filled template) rather than persisting
 *    imported data,
 *  - tracks per-Variant errors in the same ImportJob error log.
 *
 * Context keys:
 *  - shop_id    (int) — the marketplace Shop whose stock/SKU mapping to use
 *  - variant_ids (list<int>) — Variants selected on the Listing Coverage page
 */
class StartTemplateFill
{
    /**
     * @param  class-string  $fillerClass  must implement TemplateFillImporter
     * @param  array<string, mixed>  $context
     */
    public function handle(UploadedFile $file, string $fillerClass, array $context = []): ImportJob
    {
        if (! is_subclass_of($fillerClass, TemplateFillImporter::class)) {
            throw new InvalidArgumentException("[{$fillerClass}] must implement ".TemplateFillImporter::class);
        }

        $tenant = app(TenantContext::class)->current()
            ?? throw new LogicException('A template fill needs a tenant context.');

        $storedPath = $file->store("imports/{$tenant->id}")
            ?: throw new LogicException('Failed to store the uploaded template file.');

        $importJob = ImportJob::query()->create([
            'importer' => $fillerClass,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => ImportJobStatus::Pending,
            'context' => $context === [] ? null : $context,
        ]);

        RunTemplateFillJob::dispatch($importJob->id, $tenant->id);

        return $importJob;
    }
}
