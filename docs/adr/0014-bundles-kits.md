# Bundles/Kits as virtual (at-sale) BOM, stock derived from components

A **Bundle** is a sellable, listable unit (own Master SKU, List Price, Listings, Promotions) defined by a **BOM** — a list of component `(Variant, qty)`. In MVP it is a **virtual kit**: it has **no On-Hand of its own**; its availability is **derived** = `min over components of floor(Available(component, Location) / qty)`. Selling a Bundle does not move "bundle stock" — at the moment stock would move for the order, the Bundle **expands into its components** and each component's stock moves atomically. The **stocked/assembled kit** variant (pre-assembled, with its own On-Hand) is explicitly deferred.

## Why

- **Thai sellers sell "เซ็ต" without pre-assembling them** — the items are picked and packed together at the time of sale. That is exactly the *kit / virtual* model ("a kit is bundled at the time of sale, has no in-stock quantity, only an available quantity derived from its components"), not a manufactured BOM that holds finished-good stock.
- **It keeps one source of truth.** The Bundle holds no stock to drift out of sync; real stock lives only on the component Variants in the ledger. Bundle availability is always a pure function of components, so it can never disagree with reality.
- **It reuses the kernel.** A Bundle expands to component Movements — the same `RESERVE` / `SHIP` / `RELEASE` machinery (per Location, ADR 0013), no new stock mechanics. Doing this in Phase 1 means Orders/POS/marketplace all handle bundles for free.

## How

- A Variant is a **Bundle** iff it has a **BOM** (≥1 component line `(component_variant, qty)`). Components are plain Variants (no nested bundles in MVP).
- **Derived availability:** `Available(bundle, Location) = min_i floor(Available(component_i, Location) / qty_i)`. Computed from the components' denormalized balances (cache optional; cheap min over few rows). Exported to platforms like any other Available.
- **At sale, expand atomically:** when an Order reserves/ships a Bundle line, the system emits `RESERVE` / `SHIP` (and on cancel `RELEASE`) for **each component × qty** at the order's fulfilment Location — all-or-nothing. POS immediate-deduction expands to component `SHIP`s at the Register's Location.
- **COGS / profit:** `COGS(bundle) = Σ component Cost Price (at sale date) × qty` — uses the existing Cost Price history. The Bundle itself carries a sale price (List/Deal Price), not a cost.
- **Catalog/listing:** a Bundle lists and prices like any Variant (Platform SKU mapping, Deal Price, Promotions). An imported order line whose SKU resolves to a Bundle is expanded for stock, kept as a Bundle line for revenue.

## Consequences

- Order Line may reference a Bundle Variant; **stock effects always land on components**, never on the Bundle.
- A Bundle never appears in the stock ledger as a stock-holding item — only its components do.
- Nested bundles and stocked/assembled kits (assembly/disassembly Movements giving a kit its own On-Hand) are **additive** later; the BOM structure already accommodates them.
