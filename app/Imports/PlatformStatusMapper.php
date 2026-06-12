<?php

namespace App\Imports;

use App\Enums\OrderStatus;

/**
 * The fail-loud status mapping hook (ADR 0005 / CONTEXT.md Order Status):
 * each Phase-4 platform importer implements this. A native status with no
 * mapping entry must throw UnmappedPlatformStatusException — never default
 * — so a Platform introducing a new status can't corrupt the lifecycle
 * unnoticed; the pipeline holds the row and reports it.
 */
interface PlatformStatusMapper
{
    /**
     * @throws UnmappedPlatformStatusException
     */
    public function map(string $nativeStatus): OrderStatus;
}
