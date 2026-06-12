# RLS plumbing is first-party minimal code for now — stancl/tenancy v4 is not yet released (amends ADR 0016 pillar 4)

---
Status: accepted — **amends ADR 0016** (replaces its pillar 4 "use stancl/tenancy v4's RLS
bootstrapper"; pillars 1–3 — two roles, ENABLE+FORCE RLS, session-var context — are unchanged and
implemented exactly as written)
---

At build time (June 2026) **stancl/tenancy v4 does not exist as a stable release**: the latest tag
is v3.10.0 (2026-03-18), v4 exists only as a documentation preview, and its PostgreSQL RLS feature
is explicitly labelled *experimental*. ADR 0016's instruction to "use the vetted package, not custom
security plumbing" cannot be satisfied — installing `dev-master` of an unreleased branch of a
security-critical package is *less* vetted than small first-party code.

So the session-variable / scope plumbing is implemented **first-party and minimal**:

- `app/Tenancy/TenantContext.php` — the per-request tenant holder; setting it runs
  `set_config('app.current_tenant', <id>, false)` on the DB session (re-applied on every
  `ConnectionEstablished` event so reconnects can't drop the context).
- `app/Tenancy/BelongsToTenant.php` + `TenantScope` — the application global scope (auto-filter +
  auto-fill `tenant_id` on create). With **no tenant context the app scope does not filter**
  (matching the stancl/spatie convention so system code can run) — safety then comes from the RLS
  layer, whose policy resolves a missing/empty session var to `NULL` and therefore **fails closed
  (zero rows)**.
- `app/Tenancy/Rls.php` — the shared migration helper from ADR 0016: `ENABLE` + `FORCE ROW LEVEL
  SECURITY` + the idempotent `tenant_isolation` policy
  (`USING`/`WITH CHECK` on `tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::bigint`).

**When stancl/tenancy v4 ships a stable release with a non-experimental RLS bootstrapper, migrating
to it supersedes this ADR** — the surfaces are deliberately shaped to match (same session var, same
policy shape, same two-role model), so the swap is a replacement of `TenantContext` wiring, not a
schema change.

## Why

- **An unreleased dev branch is the worst of both worlds** for the highest-blast-radius code in the
  system: unpinnable, unaudited, changing under us — strictly worse than ~100 lines of first-party
  code that the mandatory cross-tenant isolation suite (ADR 0011, Issue #7) proves at the DB layer.
- **The custom surface stays tiny and standard-shaped.** The two-role split and the
  `ENABLE+FORCE+policy` migrations were always ours to write (stancl would not have written our
  migrations); what we add is only the context-holder + global scope, both textbook Laravel patterns.
- **The decision is explicitly temporary and convergent** — every shape matches what stancl v4
  documents, so adopting it later is additive.

## Consequences

- ADR 0016 pillar 4 is replaced; its pillars 1–3 and all its policy/role requirements stand.
- The isolation proof relies on `FORCE` applying RLS **to the table owner too**: test connections
  (local dev `all_ecom`, CI's provisioned non-superuser role) are owners but not superusers, so RLS
  demonstrably blocks them. CI must NOT run tests as the Postgres-image superuser (superusers always
  bypass RLS) — the workflow provisions a non-superuser role first.
- Dev/prod runtime still needs the separate non-owner app role (ADR 0016 pillar 1); provisioning it
  requires cluster-admin rights (`database/scripts/provision_db_roles.sql`, a ready-for-human step).
