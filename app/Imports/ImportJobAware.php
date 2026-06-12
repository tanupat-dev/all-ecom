<?php

namespace App\Imports;

use App\Models\ImportJob;

/**
 * An Importer that needs its ImportJob — e.g. to use it as the idempotency
 * ref of the rows it writes (a queue retry re-streams the whole file; rows
 * already applied must not double-apply). The pipeline injects it before
 * the first mapRow().
 */
interface ImportJobAware
{
    public function setImportJob(ImportJob $importJob): void;
}
