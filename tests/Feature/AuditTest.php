<?php

use App\Actions\Audit\RecordAuditLog;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('records who approved an admin-gated action', function () {
    $tenant = Tenant::query()->create(['name' => 'A']);
    app(TenantContext::class)->set($tenant);
    $admin = User::factory()->create();
    actingAs($admin);

    $log = app(RecordAuditLog::class)->handle('pos.return.approve', details: ['order_id' => 42]);

    expect($log->tenant_id)->toBe($tenant->id)
        ->and($log->created_by)->toBe($admin->id)
        ->and($log->action)->toBe('pos.return.approve')
        ->and($log->details)->toBe(['order_id' => 42]);
});

it('refuses to record an audit log without an authenticated approver', function () {
    $tenant = Tenant::query()->create(['name' => 'A']);
    app(TenantContext::class)->set($tenant);

    app(RecordAuditLog::class)->handle('order.void');
})->throws(LogicException::class, 'authenticated approver');

it('is append-only: an audit log can never be updated', function () {
    $log = recordSampleAuditLog();

    $log->update(['action' => 'rewritten']);
})->throws(LogicException::class, 'append-only');

it('is append-only: an audit log can never be deleted', function () {
    $log = recordSampleAuditLog();

    $log->delete();
})->throws(LogicException::class, 'append-only');

it('passes the cross-tenant isolation harness', function () {
    assertTenantIsolation(function (): AuditLog {
        actingAs(User::factory()->create());

        return app(RecordAuditLog::class)->handle('order.void');
    });
});

function recordSampleAuditLog(): AuditLog
{
    $tenant = Tenant::query()->create(['name' => 'A']);
    app(TenantContext::class)->set($tenant);
    actingAs(User::factory()->create());

    return app(RecordAuditLog::class)->handle('order.void');
}
