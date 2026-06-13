# The Promotion Line is the authority for Deal Price; ListingVariant.deal_price is its denormalized cache

Phase 7 introduces the **Promotion / Promotion Line** model (CONTEXT.md: Promotion). A Promotion is either a `base` markdown (no time window, at most one active per Shop — the platforms' "Product Discount") or a `campaign` (a time-bounded extra discount with `start_at`/`end_at`). Each **Promotion Line** is one Variant × Listing carrying its own `deal_price` (THB satang). The **Effective Price** of a Listing-Variant at time T resolves as: an in-window `campaign` line's `deal_price`, else the `base` line's `deal_price`, else the Variant's **List Price** — with the MVP invariant that exactly **one** Promotion Line is active at any T (no overlapping campaigns on one Listing-Variant).

The pre-existing `ListingVariant.deal_price` column (the lean Phase-4 field, ADR 0010) is **no longer an independently-edited price**: it becomes a **denormalized cache of the currently-active Promotion Line's `deal_price`** (null when only List Price applies), recomputed whenever a Promotion or line for that Listing-Variant changes or a campaign window opens/closes. The **Promotion Line is the single source of truth**; the cache exists only so the read paths that already consume `ListingVariant.deal_price` (the Channel Upload Template export, listing UI) stay O(1) and unchanged.

## Why

- **CONTEXT already resolves Effective Price against Promotion Lines, not the lean field.** The glossary's chain is "campaign line → base line → List Price"; `ListingVariant.deal_price` is not in it. So the Promotion Line must be authoritative the moment Promotions exist; keeping the lean field as a second, independently-editable price would create two sources of truth for the same number — the classic drift bug.
- **It is the project's standard denormalization shape.** On-Hand/Reserved are columns updated in the same transaction as the Stock Movement append (never `SUM()` the ledger); `Order.actual_net` is the cycle-aggregate written by `UpsertAccountingCycle`; `Order.expected_net` is written by `ComputeExpectedNet`. A cache of `ResolveEffectivePrice(now())` on `ListingVariant.deal_price` is the same pattern — the rule lives in an Action, the hot read is a column. Reports/exports never scan promotions at request time (ROADMAP Phase-1 scaling rule).
- **The export already reads the cache.** The Channel Upload Template fillers (Phase 9) read `ListingVariant` fields; the Lazada filler explicitly notes `sku.special_price.*` as "Deal Price = Phase 7". Making the Promotion Line write through to the cache means #75 fills the discount column from the same field it already knows, no export rework.
- **Base-vs-campaign as one model with a discriminator matches the rest of the codebase** (the unified Order's `platform_type`, the cycle discriminator). A campaign is a base-shaped row plus a time window; one table with a `type` enum + nullable `start_at`/`end_at` is simpler than two tables and makes the one-active-line resolution a single query.

## Consequences

- `ResolveEffectivePrice(ListingVariant, ?at)` is the authority (Action); `ListingVariant.deal_price` is a write-through cache = `ResolveEffectivePrice(now())`'s Deal Price (null if List Price applies). It is recomputed on every Promotion/line change for that Listing-Variant. The time-boundary case (a campaign opening/closing with no edit) is handled by recomputing on read for the live Effective Price and treating the cache as "best-known for export until the next refresh" — a scheduled/triggered refresh keeps it current; the Action is always exact.
- `ListingVariant.deal_price` stops being directly editable in the listing UI — it is set only by the Promotion machinery. Any prior direct-edit path is removed/redirected (consistency sweep).
- One active `base` Promotion per Shop, and no overlapping `campaign` lines per Listing-Variant, are fail-loud invariants (ADR 0005 posture) enforced in the create/activate Action, not left to the UI.
- Deal Price is always stored as integer satang even when entered as `% off` (CONTEXT: Deal Price; ADR 0015) — the percentage is converted at the input boundary and never persisted as a rate.
- POS does not pull Promotions in MVP (CONTEXT: Manual Discount; ADR 0010) — unaffected.

## Considered options

- **Keep `ListingVariant.deal_price` as an independently-editable base price; add Promotions only for campaigns.** Rejected — two sources of truth for the base Deal Price (the field and the base Promotion Line), guaranteed drift, and it contradicts CONTEXT's resolution chain which already routes through Promotion Lines.
- **Drop `ListingVariant.deal_price` entirely; resolve Effective Price live everywhere.** Rejected for the hot read paths — the export and listing grid would scan Promotions per row at request time, violating the Phase-1 scaling rule; the cache is cheap and matches the rest of the codebase.
- **Two tables (base_promotions, campaign_promotions).** Rejected — duplicates the line structure and the resolution query; a `type` discriminator + nullable window is the established pattern here.
