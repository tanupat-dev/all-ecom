# Tenant RLS enforcement: stancl/tenancy RLS bootstrapper + a non-owner app role + FORCE ROW LEVEL SECURITY

---
Status: accepted — **refines ADR 0011** (pins the *how* of its row-level enforcement; does not supersede it). **Pillar 4 amended by ADR 0018**: stancl/tenancy v4 was not yet released at build time, so the plumbing is first-party minimal code shaped to converge on the v4 bootstrapper.
---

ADR 0011 mandates row-level tenant isolation defended twice — an application global scope **and** PostgreSQL Row-Level Security (RLS), with "the app connects as a non-owner DB role". It did not pin the concrete mechanism. This ADR does:

1. **Two database roles, split by job.** A privileged **owner/migration role** owns the schema and runs migrations; a restricted **non-owner app role** (granted only `SELECT/INSERT/UPDATE/DELETE`, never table ownership, never `BYPASSRLS`) is used at runtime. RLS only applies to a role that is neither the table owner nor a superuser/`BYPASSRLS` role — so the runtime role must be the restricted one.
2. **`ENABLE` + `FORCE ROW LEVEL SECURITY` on every domain table.** `FORCE` makes the policies apply **even to the table owner**, closing the single most common RLS footgun (the owner silently bypassing RLS). Belt-and-suspenders with rule 1: even if something connects as the owner, isolation still holds.
3. **Tenant context via a session variable.** At tenant resolution each request runs `set_config('app.current_tenant', <tenant_id>, false)`; every domain table's policy is `USING (tenant_id = current_setting('app.current_tenant')::bigint)` (plus a matching `WITH CHECK` on writes).
4. **Use stancl/tenancy v4's PostgreSQL RLS bootstrapper** to drive 1 + 3 — it switches the connection to the dedicated non-owner RLS DB user and sets the session variable on tenant init, reverting on tenant end. We do not hand-roll the connection-switching / context-setting security code.

## Why

- **The owner-bypass footgun is invisible and catastrophic here.** If Laravel connects as the role that owns the tables (the default when one role runs both migrations and runtime), RLS does nothing while *looking* enabled — exactly the silent-leak failure ADR 0011 exists to prevent. The non-owner role + `FORCE` is the documented standard fix ("always use FORCE ROW LEVEL SECURITY … the most common RLS misconfiguration"; "have your application connect as a user other than the owner").
- **Don't hand-roll security.** Tenant isolation is the highest-blast-radius code in the system (a miss leaks one seller's data to another). The Laravel-ecosystem standard is to use a vetted package, not custom security plumbing — the same reasoning that made ADR 0012 adopt spatie/laravel-permission instead of a hand-built RBAC. ADR 0011 already adopted **stancl/tenancy**, whose v4 ships a PostgreSQL RLS bootstrapper that does exactly this; using it keeps our custom surface to the policy migrations only.
- **It composes with the app global scope.** The `BelongsToTenant` global scope (ADR 0011) stays the developer-ergonomic default-on layer; RLS is the DB backstop. Two independent layers, as ADR 0011 requires.

## How

- **Roles:** an owner/migration role (owns objects, runs `php artisan migrate`) + a non-owner app/RLS role for runtime (the stancl RLS user). In dev the existing `all_ecom` role is the owner; a separate restricted RLS role is added. Neither runtime role has `BYPASSRLS`.
- **Migrations** (run as the owner) define, per domain table: `ALTER TABLE … ENABLE ROW LEVEL SECURITY`, `… FORCE ROW LEVEL SECURITY`, and the `tenant_isolation` policy (`USING` + `WITH CHECK` on `tenant_id = current_setting('app.current_tenant')::bigint`). A shared migration helper applies all three so no table is forgotten.
- **`tenant_id` is indexed leading** (already required by ADR 0011 / CONVENTIONS) — RLS adds a `tenant_id = …` predicate to every query, so the index is mandatory, not optional.
- **stancl/tenancy v4 PostgresRLSBootstrapper** manages the runtime role switch + `set_config` of `app.current_tenant`; the app's default (central) connection uses the non-owner role.

## Considered options

- **(chosen) stancl RLS bootstrapper + non-owner role + FORCE RLS.** Least custom security code, consistent with ADR 0011's stancl adoption, standard-correct.
- **Hand-rolled middleware + manual two-role setup.** Implements the same three pillars but writes the connection-switch / context-set security code ourselves — more blast-radius surface for no benefit over the vetted package. Rejected.
- **Single role + FORCE RLS only (no separate app role).** Simpler role management, but the runtime role still holds DDL/ownership privileges (violates least-privilege and ADR 0011's explicit "non-owner" requirement) and a `BYPASSRLS`/superuser still bypasses. Rejected — `FORCE` is a complement to the non-owner role, not a replacement.
- **App global scope only, no RLS.** Rejected by ADR 0011 already (a single forgotten filter leaks data); RLS is the required second layer.

## Consequences

- Two DB roles to provision per environment (owner + non-owner RLS role); `.env`/deploy must configure the RLS user (stancl's `TENANCY_RLS_*`). The dev Postgres setup must add the restricted role alongside `all_ecom`.
- Every domain-table migration must call the RLS helper (ENABLE + FORCE + policy); a table that skips it is a tenant-leak — the cross-tenant isolation test suite (ADR 0011) must assert RLS actually blocks at the DB layer, not just the app scope (e.g. query as the RLS role with the wrong `app.current_tenant` and get zero rows).
- Migrations and runtime use different roles/connections — the build must keep migration-as-owner vs runtime-as-RLS-user straight (stancl handles the runtime switch).
- This unblocks ROADMAP Phase 0's tenancy/RLS slice: it is now fully specified (AFK), no open decision remaining.
