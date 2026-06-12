<?php

namespace App\Imports\ChannelTemplate;

use App\Imports\RowImportException;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contract for a per-platform Channel Upload Template filler (ADR 0019,
 * Issue #57–59). Each platform implements this to tell RunTemplateFillJob:
 *
 *  - which sheet to target
 *  - which row data starts at (after the preamble)
 *  - how to map a Variant to a map of column-key-prefix → cell value
 *
 * mapVariant() must be fail-loud (ADR 0005): throw RowImportException for
 * any required column that cannot be filled. The caller retains all good
 * rows and holds the bad ones.
 */
interface TemplateFillImporter
{
    /**
     * The display name of the sheet to write data into (matched against the
     * workbook's sheet name exactly, not a position index).
     */
    public function targetSheet(): string;

    /**
     * 1-based row index where data rows start (after all preamble rows).
     */
    public function dataStartRow(): int;

    /**
     * Map one Variant to its column values for the template. Returns a map of
     * column key prefix (before the `|` suffix) → cell value (string for text
     * cells, int/float for numeric cells).
     *
     * $productVariants is the full ordered collection of all Variants for the
     * same Product, passed in so the filler can determine parent-SKU / option
     * title logic without issuing extra queries per Variant.
     *
     * Throws RowImportException (ADR 0005) if a required column cannot be
     * filled — the caller holds this Variant and reports it in the ImportJob
     * error log, but continues filling the remaining Variants.
     *
     * @param  Collection<int, Variant>  $productVariants
     * @return array<string, string|int|float> column key prefix → cell value
     *
     * @throws RowImportException
     */
    public function mapVariant(
        Variant $variant,
        Shop $shop,
        Location $location,
        Collection $productVariants,
    ): array;
}
