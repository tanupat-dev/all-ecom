<?php

namespace App\Jobs;

use App\Enums\ImportJobStatus;
use App\Enums\ListingStatus;
use App\Imports\ChannelTemplate\TemplateFillImporter;
use App\Imports\RowImportException;
use App\Models\ImportJob;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Xlsx\WorkbookSurgeon;
use App\Tenancy\RestoreTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Queued Channel Upload Template fill (ADR 0019, Phase 9 B).
 *
 * Reads the blank template the seller uploaded, fills only the
 * owned columns for each selected Variant (via the platform's
 * TemplateFillImporter), stores the result file, then upserts
 * ListingVariants with listing_status = draft (never downgrades
 * existing `listed` rows). Per-Variant errors are held fail-loud
 * (ADR 0005) — good rows still fill.
 *
 * Cross-tenant isolation: BelongsToTenant's global scope is active
 * (RestoreTenantContext middleware) — whereIn on variant_ids silently
 * discards any IDs belonging to another tenant, which are then
 * reported as "not found" errors.
 *
 * Result: stored at `template-results/{tenant_id}/{job_id}-filled.xlsx`
 * on the local disk. The path is written into ImportJob.context['result_path']
 * for the download action to resolve.
 */
class RunTemplateFillJob implements ShouldQueue
{
    use Queueable;

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

        try {
            /** @var TemplateFillImporter $filler */
            $filler = app($importJob->importer);

            $shopId = $importJob->context['shop_id'] ?? null;

            // Cast every element to int up-front so foreach yields int values.
            $variantIds = array_map(
                static function (mixed $id): int {
                    if (is_int($id)) {
                        return $id;
                    }

                    if (is_string($id)) {
                        return (int) $id;
                    }

                    return 0;
                },
                (array) ($importJob->context['variant_ids'] ?? [])
            );

            if (! is_numeric($shopId)) {
                throw new \RuntimeException('RunTemplateFillJob requires a numeric shop_id in context.');
            }

            $shop = Shop::query()->findOrFail((int) $shopId);
            $location = Location::query()->findOrFail($shop->location_id);

            // BelongsToTenant scope auto-filters to current tenant — cross-tenant
            // IDs are silently invisible here, then reported as "not found" below.
            /** @var Collection<int, Variant> $variants */
            $variants = Variant::query()
                ->whereIn('id', $variantIds)
                ->with(['product.images', 'product.variants'])
                ->get()
                ->keyBy('id');

            $errors = [];
            $fillData = []; // variant_id (int) => [colKey => value]

            foreach ($variantIds as $variantId) {
                if (! $variants->has($variantId)) {
                    $errors[] = [
                        'row' => $variantId,
                        'message' => "Variant #{$variantId} not found — may belong to another tenant or does not exist.",
                    ];

                    continue;
                }

                $variant = $variants->get($variantId);

                if ($variant === null) {
                    continue; // defensive; cannot happen after has() check
                }

                $product = $variant->product;
                $productVariants = $product !== null
                    ? $product->variants
                    : new Collection;

                try {
                    $fillData[$variantId] = $filler->mapVariant($variant, $shop, $location, $productVariants);
                } catch (RowImportException $e) {
                    $errors[] = ['row' => $variantId, 'message' => $e->getMessage()];
                }
            }

            // ── Fill the template ──────────────────────────────────────────

            $resultPath = null;

            if ($fillData !== []) {
                $xlsxPath = Storage::disk('local')->path($importJob->stored_path);
                $surgeon = new WorkbookSurgeon($xlsxPath);

                // Resolve the target sheet name from the workbook (dynamic for
                // Lazada; a no-op read for Shopee/TikTok fixed-name sheets).
                $targetSheet = $filler->resolveTargetSheet($xlsxPath);
                $keySheet = $filler->keySheet($targetSheet);
                $keyRow = $filler->keyRow();
                $dataRow = $filler->dataStartRow();

                foreach ($fillData as $colValues) {
                    foreach ($colValues as $keyPrefix => $value) {
                        $colIdx = $surgeon->columnIndex($keySheet, $keyPrefix, $keyRow);

                        if ($colIdx !== null) {
                            $surgeon->writeCell($targetSheet, $dataRow, $colIdx, $value);
                        }
                    }

                    $dataRow++;
                }

                // Use $this->tenantId directly — already guaranteed non-null by the job constructor.
                $resultDir = "template-results/{$this->tenantId}";
                Storage::disk('local')->makeDirectory($resultDir);
                $resultPath = "{$resultDir}/{$importJob->id}-filled.xlsx";

                $surgeon->save(Storage::disk('local')->path($resultPath));
            }

            // ── Upsert ListingVariants ─────────────────────────────────────

            DB::transaction(function () use ($variants, $fillData, $shop): void {
                foreach (array_keys($fillData) as $variantId) {
                    $variantId = (int) $variantId;
                    $variant = $variants->get($variantId);

                    if ($variant === null) {
                        continue; // defensive
                    }

                    $product = $variant->product;

                    if ($product === null) {
                        continue; // defensive
                    }

                    $listing = Listing::query()->firstOrCreate([
                        'shop_id' => $shop->id,
                        'product_id' => $product->id,
                    ]);

                    $existing = ListingVariant::query()
                        ->where('listing_id', $listing->id)
                        ->where('variant_id', $variantId)
                        ->first();

                    if ($existing !== null) {
                        // Never downgrade an already-`listed` row (ADR 0019:
                        // Platform export is ground truth — once listed, stays listed).
                        if ($existing->listing_status !== ListingStatus::Listed) {
                            $existing->update([
                                'platform_sku' => $variant->master_sku,
                                'listing_status' => ListingStatus::Draft,
                            ]);
                        }
                    } else {
                        $listing->variants()->create([
                            'shop_id' => $shop->id,
                            'variant_id' => $variantId,
                            'platform_sku' => $variant->master_sku,
                            'listing_status' => ListingStatus::Draft,
                        ]);
                    }
                }
            });

            // ── Persist progress ──────────────────────────────────────────

            $context = $importJob->context ?? [];

            if ($resultPath !== null) {
                $context['result_path'] = $resultPath;
            }

            $importJob->update([
                'status' => $errors === [] ? ImportJobStatus::Completed : ImportJobStatus::CompletedWithErrors,
                'processed_rows' => count($variantIds),
                'error_rows' => count($errors),
                'errors' => $errors === [] ? null : $errors,
                'context' => $context,
            ]);

        } catch (Throwable $e) {
            $importJob->update([
                'status' => ImportJobStatus::Failed,
                'errors' => [['row' => 0, 'message' => $e->getMessage()]],
            ]);

            throw $e;
        }
    }
}
