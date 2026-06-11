# Multi-tenant SaaS with row-level isolation (shared schema + tenant_id), defended by app global scope + PostgreSQL RLS

all-ecom is a **multi-tenant SaaS**: many independent seller businesses share one deployment. The top-level isolation boundary is the **Tenant** (one signed-up seller business). Tenancy uses the **shared-schema / row-level** model — all Tenants share the same database and tables, separated by a `tenant_id` column on every domain row — **not** schema-per-tenant or database-per-tenant. Cross-tenant isolation is enforced **twice**: an application-level **global scope** (auto-injects the tenant filter on every query) and **PostgreSQL Row-Level Security (RLS)** as a database-level backstop. This **reverses** the earlier "single-tenant MVP" assumption (Phase 0).

## Why

- **Cheapest and scales broadest for many small tenants.** The target is a large number of small Thai sellers. Shared-schema row-level keeps one codebase, one DB, and runs comfortably on a single Hetzner box (2026 benchmarks show row-level sustaining ~1,000 tenants at high CPU/memory efficiency). Database-per-tenant costs 3–5× more to operate and is only justified for regulated/heavy tenants — not our segment.
- **Retrofitting tenancy later would be the worst possible rework.** Adding `tenant_id` to every table and every query after the fact is a system-wide rewrite and risks data leaks during migration — a direct violation of the project's "build phase 1 once, don't come back" principle. So the tenant boundary is designed in from Phase 0 even though signup/billing UI is deferred.
- **Isolation between sellers is non-negotiable, so defend in depth.** The one failure mode of row-level is a forgotten `WHERE tenant_id = ?` leaking one seller's data to another. Two independent layers remove that risk: the ORM global scope (developer-ergonomic, default-on) and Postgres RLS (catches anything the app layer misses). The app connects as a non-owner DB role so RLS policies actually apply.
- **Keeps the hybrid upsell path open.** Industry pattern in 2026 is pooled shared-schema for standard tenants, with a dedicated database offered to large/enterprise tenants later. Row-level now does not block moving a specific big tenant to an isolated DB later — that is additive, not a rewrite.

## Considered options

- **Single-tenant (the prior assumption).** Rejected: the product is explicitly a platform for many businesses.
- **Schema-per-tenant.** Rejected for MVP: more operational sprawl (migrations × N schemas) with little benefit at small-seller scale.
- **Database-per-tenant.** Rejected for MVP: 3–5× ops/cost, overkill without a compliance driver; reserved as a possible future high-tier offering.

## Consequences

- **Every domain entity carries `tenant_id`** (Users, Shops, Products, Variants, Stock Movements, Orders, Returns, Accounting, Promotions, Registers, Shifts, Payments…). The **Tenant** is the new top of the hierarchy: Tenant → Users (with per-Tenant admin/cashier Roles) + Shops (across platforms) + all catalog/stock/orders.
- **Composite indexes lead with `tenant_id`** (e.g. `(tenant_id, master_sku)`, `(tenant_id, platform, shop, platform_sku)`, `(tenant_id, variant_id)` on movements). The Phase-1 scaling rules (denormalized balances, rollup tables) are **per-Tenant**.
- ADR 0008's "business" now means **Tenant**; the two-Role model operates *within* a Tenant. "Unique per business" constraints (Master SKU, barcode) mean **unique per Tenant**.
- Deferred (not the data boundary): tenant **signup / onboarding / billing / plan tiers**. The boundary itself ships from Phase 0.
- Tests must include a cross-tenant isolation suite (no query returns another Tenant's rows).
