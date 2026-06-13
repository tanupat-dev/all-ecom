<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the Deal Price cache fresh across campaign time-boundaries (ADR 0021,
// Issue #74): a campaign opening/closing fires no write, so the observer can't
// refresh the cache — an hourly sweep bounds the staleness to ≤1h.
Schedule::command('promotions:refresh-cache')->hourly();

// Emit expiry reminders for campaign Promotions approaching end_at (Issue #77;
// CONTEXT.md: "System emits expiry reminders when an active campaign approaches end_at").
// The command is idempotent — re-running reads and logs the same window.
Schedule::command('promotions:expiring')->hourly();
