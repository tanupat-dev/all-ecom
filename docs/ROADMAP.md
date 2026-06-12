# All-Ecom Build Roadmap

A greenfield build order designed to go **1 → N forward without ever looping back to fix an earlier phase**.

## Ordering principle (no-rework)

1. **Dependency-first** — build what everything depends on first (Catalog, Stock, Order kernel) before any channel/feature.
2. **Build the kernel complete, as already grilled** — Phases 1–2 must implement the final semantics immediately (immutable ledger, order-aware SHIP, unified Order, Cost Price history, multi-user + view-cost gate). Do not build an "easy version first" that has to be torn out later.
3. **Later phases only ADD** — a new slice (POS, marketplace, accounting…) extends the kernel by *adding* a new entity/field/consumer, never by *changing* the core.
4. **The only condition that allows going back to fix an earlier phase**: it genuinely conflicts with an industry standard, or the owner deliberately changes a decision. Nothing else.

Model reference: `CONTEXT.md` (glossary) + `docs/adr/0001–0019`.

---

## Phase 0 — Cross-cutting foundations (decide-once)

Things that, if chosen wrong, force a full-system rebuild. Lock them before writing the first line.

| Topic | Decision (locked) |
|---|---|
| **Tech stack** | 🔒 **LOCKED: Laravel 13 + Filament 5 + Livewire 4 + Alpine + PostgreSQL** (majors track the security-support window, ADR 0017), deployed via Forge/Ploi on Hetzner Cloud Singapore. Chosen for its two layers of high convention (Laravel + the Filament Resource pattern) → an AI can't build a messy structure. See `CONVENTIONS.md`. |
| **Guardrails** | Pint (format) · Larastan/PHPStan **level max** · Pest (test) · **Actions pattern** (1 business action = 1 class) · Form Request (validation) · Policy (role gate) · Job (bulk). Enforced by CI before merge. |
| **Money** | Integer **satang (integer)** system-wide — no float. THB only (per the THB-only decision). |
| **Time** | Store UTC, display `Asia/Bangkok`. Every milestone/timestamp is timezone-aware. |
| **Ledger pattern** | append-only / immutable as a central primitive (Stock Movement, Accounting cycle, Claim Timeline, Paid-in/out all reuse it). Change = append a new record, never update/delete. |
| **IDs** | internal numeric/uuid, never shown to the user; every entity has `created_at/updated_at` + `created_by` (User). |
| **Tenancy** | **multi-tenant row-level** (ADR 0011): create a **Tenant table** + `tenant_id` on every domain table (the FK target of Phase 1) + an **app global scope** (`BelongsToTenant` — first-party per ADR 0018 until stancl/tenancy v4 ships stable) + **Postgres RLS** (the app connects to the DB as a non-owner role) for two layers of defense. Composite indexes always lead with `tenant_id`; denormalized balances + rollups are per-Tenant. Only signup/onboarding/billing is deferred — the data boundary exists from day one. A cross-tenant isolation test is a must. |
| **Audit** | admin-gated actions (void/refund/discount) must log who approved. |
| **Queue + worker** | set up the queue (DB driver first → Redis when it grows) + a persistent worker (Supervisor/systemd, set up by Forge/Ploi) + an `import_job` status table. All bulk/async work depends on this. |
| **Bulk pipeline** | write **one central import pipeline** (upload → store → queued job → streaming parse → chunked upsert → fail-loud report + progress) reused everywhere: Stock Adjustment (1), batch List Price, marketplace import (4), accounting import (6). |

**Exit:** repo skeleton + DB + money/time primitive + auth scaffolding + queue/worker + central bulk-import pipeline ready to use.

---

## Phase 1 — Catalog + Stock kernel

The base that *everything* reads. Build it with correct semantics from the start.

**Build**
- **Product / Variant** — Master SKU, **List Price** (on the Variant, the same on every platform), **Buffer** (per Variant×Location), barcode field.
- **Location (ADR 0013)** — an entity per Tenant, auto 1 default. **Stock is per `(Variant, Location)`**. A Shop has a fulfilment Location. Build it from the start — retrofitting costs as much as `tenant_id`.
- **Bundle/Kit (ADR 0014)** — a Variant with a BOM (component Variant+qty), virtual (no On-Hand of its own). Available = min(floor(component/qty)). A bundle order line → **expands into component movements** at reserve/ship (atomic) at the fulfilment Location. COGS = Σ component cost. Build from the start because it affects order→stock.
- **Cost Price with change history** (`valid_from`) — accounting (Phase 6) needs the cost at the sale date. Storing a single value would force a rebuild.
- **Stock Movement ledger** — **9 actions** (`RECEIVE/SHIP/RESERVE/RELEASE/DAMAGE/RESTORE/RECOUNT/TRANSFER_OUT/TRANSFER_IN`), append-only, **`location_id`** on every row, `ref_type+ref_id`.
  - **SHIP is order-aware from the start**: On-Hand always −, Reserved − only by what the order actually reserved (marketplace/social = full, POS = 0). → POS (Phase 3) plugs in with no changes.
- **Derived balances per `(Variant, Location)`** — On-Hand / Reserved / **Available = On-Hand − Reserved − Buffer (may go negative)** / Damaged. (bundle Available = derived from components)
- **Stock Adjustment** — Excel in/out (recount/receive/damage/restore) + **inter-Location Transfer** (`TRANSFER_OUT`+`TRANSFER_IN` pair).

**Exit:** create products, receive stock, adjust stock, query On-Hand/Available fully. Available may go negative.

### ⚠️ Scaling design constraints (mandatory — embed from Phase 1, never do later)

Supporting **100k SKU + 10k orders/day (~15–20M movements/year) on a single box** depends on these two rules, not on machine size:

1. **Denormalized current quantities** — store On-Hand / Reserved per **`(Variant, Location)`** as a **column updated in the same transaction as the Stock Movement append**. Reading stock = O(1) (read a column); **never `SUM()` the whole ledger at runtime**. The ledger still keeps everything for audit/traceback (glossary: "derived **or denormalized** against the ledger"). bundle Available = derived from components (compute on read, cache optional).
2. **All bulk work goes through queue + streaming + chunked upsert** — import/export/recalc must not run in a web request. streaming = constant RAM no matter how many rows; chunked upsert (500–1000/chunk) = DB writes in batches.

**No-rework notes:** immutable ledger + order-aware SHIP + Available-can-go-negative + Cost history + the 2 scaling rules above = absorbs POS, marketplace oversell, accounting, **and growth to hundreds of thousands of SKUs / tens of thousands of orders per day** in advance. Scaling beyond one box (separate DB box / read replica / monthly partitioning / Redis cache for hot stock) is **purely additive — it doesn't touch domain code**.

---

## Phase 2 — Identity + Shop + Order kernel

The backbone that both POS and marketplace plug into.

**Build**
- **Tenant resolver/context** (ADR 0011) — the Tenant *table* + `tenant_id` + global-scope/RLS mechanism already exist from **Phase 0** (the FK target of Phase 1). This phase does the **tenant resolve at login** (set the current tenant for the global scope + the RLS session var) + ties it to the User. (signup/onboarding/billing deferred)
- **User / Role / Permission — custom RBAC (ADR 0012)** — spatie/laravel-permission + Filament Shield: a Permission catalogue (system-defined, granular `area.action` separating view/edit) + a custom Role per Tenant (checkbox permissions) + seed Admin/Cashier defaults. `cost.view` = the cost gate. Every gated action checks a named permission via a Policy. Build RBAC now, don't retrofit auth. (Role per-Tenant via spatie teams = tenant_id.)
- **Shop** (3 `platform_type`) + **Shop Settings** (fields are marketplace money-flow concerns; pos doesn't use them).
- **Order / Order Line / Order Status** (unified, ADR 0002) — a discriminator by platform_type, canonical 8 statuses + a fail-loud mapping hook, **Order Milestone Dates** (nullable timestamps, upsert no-null-overwrite, ADR 0004).
- **Stock hooks** — wire RESERVE/RELEASE/SHIP from the Order lifecycle into the Phase-1 ledger (compensating movement for pre-pack edits).

**Exit:** create an Order directly (manual) → fire the correct movement, query Order + status + timestamp.

**No-rework notes:** discriminator + milestone + nullable + editable-rule = supports both POS (instant) and marketplace (full lifecycle) from the design.

---

## Phase 3 — POS slice  ← first shippable product

The sales channel with zero external dependencies → real usable product fastest + validates the kernel.

**Build** (all of `## POS` in CONTEXT.md)
- **Register** (auto-provision 1 per pos Shop) + **Shift** (open/close, opening_float, blind close, expected_cash, over_short, Paid-in/out).
- **Payment** — Payment Lines, 4 tenders, split tender, change, **manual-confirm**, cash → expected_cash.
- **Checkout** — barcode/Master SKU → Variant, **List Price + Manual Discount** (line/cart, %/฿, admin-gated), close bill → `สำเร็จ` → SHIP (immediate deduction).
- **Receipt** — render from Order+Payment, `receipt_no` running per pos Shop.
- **Parked Sale** — hold at `รอชำระ`, touches no stock/money.
- **POS Return** (ADR 0009) — a negative-line Order linked to the original bill, RECEIVE, refund→drawer, admin-gated, exchange = neg+pos order.
- **over/short → Cash Over/Short** line (prepare the P&L hook).

**Exit:** open a shift → sell (split tender, discount, park a bill) → print a receipt → return goods → close the shift blind → over/short. The full storefront loop.

---

## Phase 4 — Marketplace import slice

Extends the Order kernel with an external channel. The biggest one, because it's per-platform.

**Build**
- **Listing / Platform SKU** — the projection layer (marketplace only). Build a per-Shop **`(Shop, Platform SKU) → Variant` resolution map** (a function, **many-to-one OK, not necessarily one-to-one**): a SKU repeated across listings / many SKUs → one Variant = supported; one SKU pointing at 2 Variants = **fail-loud conflict** for the seller to resolve. Master SKU unique per business. stock export writes Available to every Platform SKU of that Variant. **Scope = lean/opportunistic (B):** keep the mapping + Deal Price + whatever fields the import gives us (category/image URL) read-only — **not content management/PIM** (an OMS category, no API). The bounded channel-listing *assist* (template fill + image hosting + coverage) that was "additive later" is now specced as **Phase 9** (ADR 0019) — still not a PIM.
- **Order importers** (Shopee/Lazada/TikTok) — Excel parse, **status mapping fail-loud** (ADR 0005), upsert/dedup `(platform,shop,order_id)`, Order Line normalize→aggregate, milestone timestamps.
- **Reserved reconcile** — importer diffs Order Lines → RESERVE/RELEASE.
- **Cancellation Reason** (cancelled_by/category/source, fail-loud).
- **Oversell alert** — list candidates, import-driven resolution.
- **Stock export Excel** — Available (clamp negative→0, minus Buffer) per platform discount/stock file.

**Exit:** import orders from 3 platforms → correct stock/status, export stock back, oversell alerts.

---

## Phase 5 — Returns + Stock Return (marketplace)

**Build:** Return (header+lines, ADR 0006), `return_type`, Return Sub-Status, **Inbound Scan**, Refund Status rollup, Stock Return (RECEIVE after scan), dangling/stale detection, return import + dedup `platform_return_id`.

**Exit:** import a return → Sub-Status → Inbound Scan → stock comes back; refund_only never touches stock.

---

## Phase 6 — Accounting + Reconciliation + P&L

**Build:** Accounting Entry (**cycle-aware import**, ADR 0007), Fee Category (8), Actual Net, Expected Net, Platform Fee Profile, Reconciliation Status, Hold Period/Settlement/Expected Payout/Mismatch (marketplace), **POS direct P&L (Payment − COGS, no fee)**, **Cash Over/Short**, Expense, **combined P&L across every channel**.
- **Reports at the million-row level use a rollup table / materialized view (daily summary)** — never scan raw at runtime — consistent with the Phase-1 scaling rules.

**Exit:** import accounting → Actual Net → reconcile → overdue alerts; combined marketplace+POS P&L is correct.

---

## Phase 7 — Promotions + Margin

**Build:** Promotion / Promotion Line (base/campaign, 1 active line/T), Deal Price/Effective Price, export the discount field per platform, Margin Calculator, expiry reminder. (POS doesn't pull Promotions yet — deferred.)

**Exit:** set up a promo → Effective Price → export → margin calc recommends a price.

---

## Phase 8 — Claims

**Build:** Claim (`return_fee`/`shipping_overcharge`), 6-status lifecycle, Evidence Checklist, Claim Timeline. Depends on Returns (5) + Accounting (6).

**Exit:** auto-flag a claim from a seller-fault return / shipping overcharge → track it through to close.

---

## Phase 9 — Product Listing / Channel Upload (bounded; ADR 0019)

Helps a seller bulk-list onto Shopee/Lazada/TikTok **without an API** by filling each Platform's own downloaded bulk-upload file from one channel-agnostic master. **Not a PIM** (ADR 0019). Depends on Phase 4 (Listing / Platform SKU); independent of Phase 6 — **owner picks the slot vs Accounting.**

**Build**
- **A. Catalogue master extension** — channel-agnostic fields: Product (English name, description, brand) + Variant (package weight + dimensions); plus a **Product Image** stored on R2 (object storage), normalised 1:1.
- **B. Channel Upload Template fill** — import the Platform's downloaded template (reuse the central pipeline + format-blind reader), map columns by the **machine-key row**, fill only owned columns from the master, emit one filled file per Platform (Lazada one sheet per leaf category, ≤20). Satang→baht on fill (ADR 0015).
- **C. Listing Coverage** — Variant × Shop matrix + gap list ("not listed on this Shop"), the selection source for "list these missing ones".
- **D. Existing-listing importer** — import each Platform's "All product" export → match by Master SKU → build ListingVariant + Coverage from reality; unmatched SKU = **fail-loud** (ADR 0005), conflicting per-channel content **never auto-merged**; also populates the `(Shop, Platform SKU) → Variant` resolver.
- **E. Product Image hosting** — upload/normalise to R2, write its public URL into the template's image columns (Lazada requires a URL, Shopee/TikTok accept one); TikTok image-less rows fall to its native Draft.
- **Listing Status** (`draft`→`listed`) on ListingVariant — `draft` on fill, `listed` on seller confirm or existing-listing import.
- **Round-trip** — every seller-owned import (catalogue/prices/coverage) has a paired **export → edit → re-import** (SKU-keyed); Platform mirrors (orders/returns) export read-only.
- *(F. AI enrichment — draft descriptions / suggest category attributes — explicitly **deferred**; deterministic fill needs no AI.)*

**Exit:** author/import a master → bring in a Platform template → download a per-Platform file with owned columns + image URLs filled → coverage shows what's listed vs missing across all Shops.

---

## Dependency chain (summary)

```
0 (decide-once)
└─ 1 Catalog+Stock ──┬─ 2 Identity+Shop+Order ──┬─ 3 POS ✅ shippable
                     │                          ├─ 4 Marketplace import
                     │                          │    ├─ 5 Returns
                     │                          │    │    └─ 8 Claims ─┐
                     │                          │    └─ 9 Listing / Channel Upload (ADR 0019)
                     │                          └─ 6 Accounting ───────┘
                     └────────────────────────────── 7 Promotions
```

**The fastest sellable MVP = 0→1→2→3** (end-to-end storefront). marketplace/accounting/promo/claims/listing build on top afterward without touching 1–3.
