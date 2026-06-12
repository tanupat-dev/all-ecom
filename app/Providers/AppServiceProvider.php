<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Store UTC (APP_TIMEZONE), display Asia/Bangkok (ROADMAP Phase 0).
        FilamentTimezone::set('Asia/Bangkok');

        // A fresh DB connection must inherit the tenant context, or RLS would
        // fail closed mid-request after a reconnect (ADR 0018).
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'pgsql') {
                app(TenantContext::class)->applyToConnection($event->connectionName);
            }
        });
    }
}
