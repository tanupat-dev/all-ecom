<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Schema\Blueprint;
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

        // The audit-column standard (CONVENTIONS DB rules): every table gets
        // created_at/updated_at + created_by. AuditColumnsCoverageTest fails
        // the build on a table that skips this.
        Blueprint::macro('auditColumns', function (): void {
            /** @var Blueprint $this */
            $this->timestamps();
            $this->foreignId('created_by')->nullable()->constrained('users');
        });

        // A fresh DB connection must inherit the tenant context, or RLS would
        // fail closed mid-request after a reconnect (ADR 0018).
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'pgsql') {
                app(TenantContext::class)->applyToConnection($event->connectionName);
            }
        });
    }
}
