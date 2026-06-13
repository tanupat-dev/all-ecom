<?php

namespace App\Authorization;

use Spatie\Permission\Models\Permission;

/**
 * The system-defined Permission catalogue (ADR 0012 / CONTEXT.md:
 * Permission): granular area.action with view/edit separated. Tenants
 * compose these into Roles but can never invent new ones — every entry
 * corresponds to a real check in code. Grows as features ship.
 */
class PermissionCatalogue
{
    public const ALL = [
        'product.view',
        'product.edit',
        'stock.view',
        'stock.adjust',
        'location.view',
        'location.edit',
        'shop.view',
        'shop.edit',
        'listing.view',
        'listing.manage',
        'promotion.view',
        'promotion.manage',
        'order.view',
        'order.import',
        'return.view',
        'return.manage',
        'claim.view',
        'claim.manage',
        'accounting.view',
        'accounting.manage',
        'report.view',
        'cost.view',
        'pos.checkout',
        'pos.open_shift',
        'sale.void',
        'sale.refund',
        'sale.discount',
        'user.manage',
        'role.manage',
    ];

    /** The POS subset the default Cashier role grants (CONTEXT.md: Role). */
    public const CASHIER = [
        'product.view',
        'stock.view',
        'order.view',
        'pos.checkout',
        'pos.open_shift',
    ];

    /**
     * Idempotent — permissions are global (not team-scoped); safe to call
     * from every CreateTenant.
     */
    public static function ensureSeeded(): void
    {
        foreach (self::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}
