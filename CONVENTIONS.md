# Conventions — all-ecom

**Read this file before writing any code, every time.** Goal: one convention across the whole project, the same structure in every feature — never invent a new pattern. To deviate from this, the reason must be written in an ADR.

Sources of truth: **`CONTEXT.md`** (domain / glossary) · **`docs/adr/`** (decisions 0001–0018) · **`docs/ROADMAP.md`** (build order).

---

## Stack (locked)

- **Laravel 13** + **Filament 5** (back-office) + **Livewire 4 + Alpine** (POS reactive) + **PostgreSQL**. Framework majors track the security-support window — upgrade before a major's security EOL as routine maintenance (ADR 0017).
- **Dev runtime = WSL2 Ubuntu native** (project at `~/projects/all-ecom`, edited via VS Code Remote-WSL) — chosen over Herd (Windows-native, parity drift) and Sail/Docker (RAM-heavy) for true Linux parity at low RAM. **CI = GitHub Actions `ubuntu-latest`** (the parity net). Run Claude Code dev sessions from the WSL path so tools are native Linux. (Issue #1.)
- Deploy: **Forge/Ploi → Hetzner Cloud Singapore**. Queue: database → Redis when it grows. Worker: Supervisor.
- Do not add a large dependency / change the stack without an ADR.

## Quality gates (mandatory — must pass CI before merge)

- **Pint** — format (no custom style).
- **Larastan/PHPStan level max** — no errors.
- **Pest** — every Action/flow has a test; **a cross-tenant isolation test is a must**: every tenant-scoped table calls the shared harness **`assertTenantIsolation()`** (`tests/Helpers/TenantIsolation.php`), and two always-on guards fail the build on a model missing `BelongsToTenant` or a `tenant_id` table missing RLS (`ModelTenancyArchTest`, `RlsCoverageTest`). Tests run on **Postgres** (`all_ecom_test` locally on :5434, a service container in CI) for prod parity — RLS (ADR 0011/0016) cannot be tested on sqlite.

## Verify / hosting (see skills)

- **Verify a change** → skill `all-ecom-verify`: pick the cheapest layer that proves it — in-process Pest (Unit → Feature → `Livewire::test()`) first, browser (Pest v4/Playwright) only for the POS Alpine cart. Most changes **do not need to host a server**.
- **Must host a real server** (visual/browser) → skill `all-ecom-local-server-hosting`: run_in_background → curl readiness → **stop + verify port & tree** → no orphan. Whoever starts it is who must stop it.

## Structure / patterns (one way only)

| To do | Use this pattern | Location |
|---|---|---|
| Business logic (one action) | **Action class** (1 action = 1 class, method `handle()`) | `app/Actions/...` |
| Validation | **Form Request** | `app/Http/Requests/...` |
| Auth/permission gate | **Policy** checking a **named Permission** (spatie/laravel-permission + Filament Shield gen per-Resource) — never `if role=='admin'` | `app/Policies/...` |
| Back-office CRUD/screens | **Filament Resource** (Schema/Table/Action per the template) | `app/Filament/...` |
| POS / reactive pages | **Livewire component** (+ Alpine for the client-side cart) | `app/Livewire/...` |
| Bulk/async work (import/export/recalc) | **Job** (queued, chunked) through the central import pipeline | `app/Jobs/...` |
| Complex reusable query | Eloquent scope / Builder method | on the Model |
| Domain value object (e.g. **Money**) | final readonly class | `app/Support/...` |
| Eloquent attribute cast (e.g. **MoneyCast**) | `CastsAttributes` | `app/Casts/...` |

- **Never** put business logic in a Controller, Model, or Livewire component — move it to an **Action**.
- Controllers are thin (call an Action, return a response, nothing more). Model = relationships + casts + scopes, no heavy logic.

## Domain rules not to miss (from the ADRs)

1. **Money = integer satang** everywhere. No float. Cast to a value object/integer. THB only. (ADR 0015)
2. **Multi-tenancy (ADR 0011)** — every domain model `use BelongsToTenant` (global scope, auto) + **Postgres RLS** enabled on every table; the app connects to the DB as a **non-owner role**. Every composite index/unique leads with `tenant_id`. "Unique per business" = unique `(tenant_id, ...)`.
3. **Stock = immutable ledger (ADR 0003)** — changing stock = **append a Stock Movement** only, never update/delete. **Stock is per `(Variant, Location)`** (ADR 0013): a Movement carries `location_id`; the denormalized current-quantity column is per `(variant, location)` and updated in the **same transaction** as the movement — never `SUM()` the ledger at runtime. A cross-Location Transfer = a `TRANSFER_OUT` + `TRANSFER_IN` pair.
3b. **Bundle/Kit (ADR 0014)** — virtual, no On-Hand of its own; Available = min(floor(component/qty)). A bundle order line → **expands into component RESERVE/SHIP/RELEASE atomically** at the fulfilment Location; never move "bundle stock". COGS = Σ component cost at sale date.
4. **`SHIP` is order-aware** — On-Hand always −, Reserved − only by what the order actually reserved (POS = 0). **Available may go negative** (oversell); clamp to 0 only on export.
5. **Imports are fail-loud (ADR 0005)** — a value that can't be mapped (status/reason/SKU/fee) **is never silently defaulted** → surface an error + hold the record.
6. **SKU resolution is a function** — `(tenant_id, Shop, Platform SKU) → one Variant`; many-to-one is allowed; one SKU → 2 Variants = fail-loud. Master SKU/barcode unique per tenant.
7. **Accounting (ADR 0007)** is cycle-aware; **POS has no Accounting Entry** (P&L is direct = Payment − COGS).
8. **RBAC (ADR 0012)** — a custom Role per Tenant = a set of **Permissions** (catalogue is system-defined, granular `area.action` separating view/edit). Every gated action checks a **named Permission via a Policy** (never hardcode a role); `cost.view` = the cost-visibility gate; void/refund/discount = a permission + log who approved. Roles are per-Tenant (spatie teams), **create/edit/delete role is allowed** (deleting a role in use = strip it from users first + warn), seed Admin/Cashier defaults, never lock out the last user holding `user.manage`/`role.manage`.
9. **Cost Price has history** (`valid_from`) — profit uses the cost at the sale date.

## DB / migration

- Every table: `tenant_id` (FK, leading index), `created_at/updated_at`, `created_by`.
- Enable an **RLS policy** in the migration of every domain table — call the shared helper **`Rls::enable('table')`** (`app/Tenancy/Rls.php`: ENABLE + FORCE + `tenant_isolation` policy, ADR 0016/0018). Where the owner/runtime roles are split, migrations run as the owner: `php artisan migrate --database=pgsql_owner`.
- Index by real lookups: `(tenant_id, master_sku)`, `(tenant_id, platform, shop, platform_sku)`, `(tenant_id, variant_id)` on movements, etc.
- ledger/movements + orders: be ready for **monthly partitioning** (do it when it grows, not now).

## Naming / language

- Code/tables/fields = English, following the terms in `CONTEXT.md` (Tenant, Shop, Variant, Stock Movement, Shift, …).
- User-facing statuses/values (Order Status `รอชำระ/สำเร็จ/…`) are stored as the canonical values per CONTEXT.md.
- UI = Thai.

## Skills (use at the matching moment in the work)

- **backlog/checklist "what's left" = GitHub Issues** + triage labels → `all-ecom-triage` (`ready-for-agent` = AFK / `ready-for-human` = HITL). Break a plan → issues with `all-ecom-to-issues`.
- **end of session / "handoff" / "what next time" → `all-ecom-handoff`** (ephemeral doc in the OS temp dir, referencing artifacts + issue# + commit, not committed to the repo).
- before any non-trivial work → `all-ecom-engineering-process` (plan-first + vertical slice + AFK/HITL).
- writing each slice → `all-ecom-tdd` (red→green→refactor, behaviour through the public interface).
- before locking a design that's costly to roll back (money/stock/ordering) → `all-ecom-standard-first`.
- before designing a money/stock feature → `all-ecom-business-rules-check`.
- before creating a new construct → `all-ecom-search-before-write`.
- before committing sensitive code (tenancy/RBAC/payment/PII/SQL/secret) → `all-ecom-security-check`.
- verify a change → `all-ecom-verify` · must host a server → `all-ecom-local-server-hosting`.
- **every commit that changes behaviour/a name/a term/a decision → `all-ecom-consistency-sweep`** (sweep stale doc/comment/test/dead-code, fix in the same commit).

## When unsure

Ask / check `CONTEXT.md` + the ADRs first. If a new decision affects the structure → write a new ADR (supersede, don't edit in place); never silently drift from a convention.
