<?php

namespace App\Imports;

/**
 * The contract every Excel import implements (ROADMAP Phase 0 bulk pipeline).
 * One central pipeline (StartImport → RunImportJob) handles upload, queueing,
 * streaming parse, chunking, progress, and the fail-loud report — a concrete
 * Importer only maps rows and upserts chunks.
 */
interface Importer
{
    /**
     * Map one header-keyed spreadsheet row to its upsertable shape. Throw
     * RowImportException for anything that cannot be mapped — the pipeline
     * holds the row and surfaces it in the error report; it is NEVER
     * silently defaulted (ADR 0005).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function mapRow(array $row, int $rowNumber): array;

    /**
     * Persist one chunk of mapped rows idempotently (upsert — the pipeline
     * may retry). Runs inside a DB transaction per chunk.
     *
     * @param  list<array<string, mixed>>  $chunk
     */
    public function upsertChunk(array $chunk): void;
}
