<?php

namespace App\Actions\Catalog;

use App\Models\Variant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catalogue-master round-trip export (ROADMAP Phase 9, ADR 0019 "round-trip
 * everywhere the seller owns the data"). Streams one row per Variant keyed by
 * master_sku, including every channel-agnostic listing field the seller owns.
 *
 * List Price is intentionally excluded: price editing flows through dedicated
 * price actions, not this round-trip (ADR 0019 scope boundary — the seller
 * owns text/dimension master data here; price management is a separate flow).
 *
 * Tenant scope: the BelongsToTenant global scope on Variant restricts the
 * query to the current tenant automatically — no manual tenant_id filter needed.
 *
 * Re-import via CatalogueMasterImporter. Blank-cell semantics: a blank cell
 * on re-import sets the field to null (WYSIWYG).
 */
class ExportCatalogueMaster
{
    /** Stable column headers — the importer keys on these names. */
    public const COLUMNS = [
        'master_sku',
        'product_name',
        'english_name',
        'description',
        'brand',
        'variant_name',
        'package_weight_g',
        'package_width_mm',
        'package_length_mm',
        'package_height_mm',
    ];

    /**
     * @return list<array{master_sku: string, product_name: string, english_name: string|null, description: string|null, brand: string|null, variant_name: string|null, package_weight_g: int|null, package_width_mm: int|null, package_length_mm: int|null, package_height_mm: int|null}>
     */
    public function handle(): array
    {
        $rows = [];

        Variant::query()
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('id')
            ->each(function (Variant $variant) use (&$rows): void {
                $product = $variant->product()->firstOrFail();

                $rows[] = [
                    'master_sku' => $variant->master_sku,
                    'product_name' => $product->name,
                    'english_name' => $product->english_name,
                    'description' => $product->description,
                    'brand' => $product->brand,
                    'variant_name' => $variant->name,
                    'package_weight_g' => $variant->package_weight_g,
                    'package_width_mm' => $variant->package_width_mm,
                    'package_length_mm' => $variant->package_length_mm,
                    'package_height_mm' => $variant->package_height_mm,
                ];
            });

        return $rows;
    }

    public function download(): StreamedResponse
    {
        $rows = $this->handle();

        return response()->streamDownload(function () use ($rows): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(self::COLUMNS));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues([
                    $row['master_sku'],
                    $row['product_name'],
                    $row['english_name'],
                    $row['description'],
                    $row['brand'],
                    $row['variant_name'],
                    $row['package_weight_g'],
                    $row['package_width_mm'],
                    $row['package_length_mm'],
                    $row['package_height_mm'],
                ]));
            }

            $writer->close();
        }, 'catalogue-master-'.now()->format('Ymd-Hi').'.xlsx');
    }
}
