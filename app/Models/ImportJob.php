<?php

namespace App\Models;

use App\Enums\ImportJobStatus;
use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Status row for one run of the central bulk-import pipeline (ROADMAP
 * Phase 0): progress counters + the fail-loud error report (ADR 0005).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $importer
 * @property string $original_filename
 * @property string $stored_path
 * @property ImportJobStatus $status
 * @property int $processed_rows
 * @property int $error_rows
 * @property list<array{row: int, message: string}>|null $errors
 * @property array<string, mixed>|null $context
 * @property int|null $created_by
 */
class ImportJob extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = [
        'importer',
        'original_filename',
        'stored_path',
        'status',
        'processed_rows',
        'error_rows',
        'errors',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportJobStatus::class,
            'errors' => 'array',
            'context' => 'array',
        ];
    }
}
