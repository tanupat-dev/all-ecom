# Per-Tenant custom roles with a system-defined permission catalogue (RBAC)

---
Status: accepted — **supersedes ADR 0008**
---

Each **Tenant** can create and edit its own **Roles** and choose, by checkbox, which **Permissions** each Role grants. A **User** holds one or more Roles; effective access = the union of their Roles' Permissions. The **Permission catalogue is system-defined** (the application ships the list, because every Permission maps to a real check in code) — Tenants compose Permissions into Roles, they do not invent Permissions. Every Tenant is seeded with two editable default Roles — **Admin** (all Permissions) and **Cashier** (POS subset). This **supersedes ADR 0008**'s fixed two-role model: the former hard-coded gates ("view cost", void/refund/discount approval) become ordinary Permissions.

## Why

- **The product needs it.** Sellers' org shapes differ — one wants a "ผู้จัดการ" who sees reports but not cost, another a "คลัง" who only edits stock. A fixed admin/cashier pair can't express that; per-Tenant custom roles can.
- **The stack makes it cheap.** ADR 0008 deferred RBAC because a permission matrix is expensive to build from scratch. On the locked stack it isn't: **spatie/laravel-permission** (the convention-standard RBAC) + **Filament Shield** provide per-Resource permissions and a checkbox role-management UI almost for free — so the cost that justified the deferral no longer exists.
- **It generalises the gates we already have.** "view cost", void, refund, discount were going to be special-cased anyway; expressing them as Permissions enforced through Policies is cleaner and uniform.

## How

- **spatie/laravel-permission** for Roles/Permissions; checks run through Laravel **Policies** (and Filament's `can*()`); **Filament Shield** generates the per-Resource Permissions + the role/permission admin UI.
- **Permission catalogue** = code-defined constants, granular at **(area × action)** so access and edit are separable (a Role can *view* without *edit*). Per data area Filament Shield generates the CRUD verbs (`view_any` / `view` / `create` / `update` / `delete`), plus custom action Permissions for special operations. Examples: `product.view` / `product.edit`, `stock.view` / `stock.adjust`, `order.view` / `order.import`, `accounting.view` / `accounting.manage`, `report.view`, `cost.view` (the former "view cost" gate), `pos.checkout`, `pos.open_shift`, `sale.void`, `sale.refund`, `sale.discount`, `user.manage`, `role.manage`. So "เข้าถึงได้แต่แก้ไม่ได้" = grant the `.view` Permission, withhold `.edit` / `.manage`. Grows with features.
- **Multi-tenant scoping (ADR 0011):** Roles are **per-Tenant** (spatie "teams" = `tenant_id`); the Permission catalogue is global (same capabilities for everyone), Role definitions and assignments are tenant-scoped and never leak across Tenants.
- **CRUD on Roles:** a Tenant can **create, edit, and delete** its own Roles. Deleting a Role that is **in use** must first strip it from (or reassign) the Users holding it — surfaced as a warning, never a silent orphan. Default Admin/Cashier Roles are editable and deletable too, subject to the invariant below.
- **Seed + lock-out safeguard:** each new Tenant is seeded the Admin + Cashier defaults (editable). Any create/edit/delete of Roles or Users must **never leave the Tenant with zero Users holding both `user.manage` and `role.manage`** — the operation is blocked if it would.

## Consequences

- Every gated action checks a **named Permission** via a Policy — no ad-hoc `if role == admin`.
- The "view cost" gate is now the `cost.view` Permission, hiding cost/COGS/margin wherever it's absent.
- New features must register their Permissions in the catalogue (and Shield surfaces them in the role UI).
- ADR 0008 is superseded but its *intent* (an owner-vs-counter-staff split) survives as the seeded default Roles.
