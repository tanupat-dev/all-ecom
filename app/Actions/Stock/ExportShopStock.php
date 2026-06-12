<?php

namespace App\Actions\Stock;

use App\Enums\PlatformType;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Shop;
use LogicException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The per-Shop stock export (ROADMAP Phase 4): every mapped Platform SKU
 * gets the fulfilment Location's Available — several SKUs of one Variant
 * all reflect the one shared pool (CONTEXT.md: Platform SKU); a Bundle
 * exports its derived Available (ADR 0014); negative clamps to 0 only
 * here, on export (CONTEXT.md: Available Stock). Generic two-column shape
 * for MVP — matching each platform's exact upload template follows once
 * `ref doc/` is restored.
 */
class ExportShopStock
{
    /**
     * @return list<array{platform_sku: string, qty: int}>
     */
    public function handle(Shop $shop): array
    {
        if ($shop->platform_type !== PlatformType::Marketplace) {
            throw new LogicException('Stock export is for marketplace Shops — pos/social have no Platform to push to.');
        }

        $location = Location::query()->findOrFail($shop->location_id);
        $rows = [];

        $mappings = ListingVariant::query()
            ->where('shop_id', $shop->id)
            ->with('variant')
            ->orderBy('id')
            ->get();

        foreach ($mappings as $mapping) {
            $rows[] = [
                'platform_sku' => $mapping->platform_sku,
                'qty' => max(0, $mapping->variant()->firstOrFail()->availableAt($location)),
            ];
        }

        return $rows;
    }

    public function download(Shop $shop): StreamedResponse
    {
        $rows = $this->handle($shop);

        return response()->streamDownload(function () use ($rows): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['platform_sku', 'qty']));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues([$row['platform_sku'], (string) $row['qty']]));
            }

            $writer->close();
        }, "stock-{$shop->name}-".now()->format('Ymd-Hi').'.xlsx');
    }
}
