<?php

namespace App\Imports;

/**
 * An opt-in for an importer whose file does NOT follow the pipeline's default
 * single-sheet / header-on-row-1 shape — e.g. a platform accounting export
 * that buries its data sheet among several and prints a preamble above the
 * header row (Shopee Income). Checked with instanceof in RunImportJob, exactly
 * like ImportJobAware, so it is NOT part of the Importer contract and nothing
 * existing changes. An importer that does not implement it keeps the byte-for-
 * byte default: first sheet, header on row 1, data from row 2.
 */
interface HasSheetLayout
{
    /** The sheet to read by name; null = the first sheet (current default). */
    public function sheetName(): ?string;

    /** 1-based row the headers live on; rows above it are skipped preamble. Default 1. */
    public function headerRowOffset(): int;
}
