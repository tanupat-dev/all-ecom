<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\BelongsToTenant;

/*
 * Every domain model is tenant-scoped (CONVENTIONS domain rule 2, ADR 0011).
 * A new model that forgets `use BelongsToTenant` fails the build here.
 * Exceptions: Tenant (the registry itself) and User (tied to its Tenant at
 * Phase 2 — re-scope then).
 */
it('gives every domain model the BelongsToTenant trait', function () {
    $exempt = [Tenant::class, User::class];

    $files = glob(app_path('Models').'/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (in_array($class, $exempt, true)) {
            continue;
        }

        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} must use BelongsToTenant (ADR 0011).");
    }
});
