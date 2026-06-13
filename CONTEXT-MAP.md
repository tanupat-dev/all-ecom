# Context Map

all-ecom is a multi-tenant SaaS for small Thai sellers to manage stock / orders / accounting / promotions across marketplaces (Shopee / Lazada / TikTok via Excel import/export — no API) plus a physical POS.

Its domain language outgrew a single glossary, so it is split into **bounded contexts** — each with its own `CONTEXT.md`-style glossary under [`docs/context/`](./docs/context/). This map is the index + how the contexts relate. (Pattern: `mattpocock/skills` → grill-with-docs.) System-wide, costly-to-reverse decisions live in [`docs/adr/`](./docs/adr/); a context-specific term links the ADR that pins it.

When grilling or building, load only the relevant context's glossary — not all of them.

## Contexts

- [Tenancy & Access](./docs/context/tenancy.md) — the Tenant isolation boundary, Platforms/Shops, Users / Roles / Permissions (shared kernel)
- [Catalog](./docs/context/catalog.md) — Products, Variants, Bundles, SKUs, per-Shop Listings, Channel Upload
- [Inventory](./docs/context/inventory.md) — append-only stock ledger; On-Hand / Reserved / Available; Stock Movements
- [Orders](./docs/context/orders.md) — Order + Lines, Order Status, Returns / Refunds / Cancellations, milestones
- [Fulfilment](./docs/context/fulfilment.md) — Dispatch Round, Pick List, Dispatch Manifest, Printed Flag
- [Pricing & Promotions](./docs/context/pricing.md) — Promotions, Promotion Line, List / Deal / Effective Price
- [Accounting & Profitability](./docs/context/accounting.md) — Fee Profiles, Accounting Entry, Reconciliation, P&L
- [Claims](./docs/context/claims.md) — Return Reason fault classification, Claim lifecycle
- [POS](./docs/context/pos.md) — Registers, Shifts, Payments, Receipts, POS Return

## Relationships

- **Tenancy** is the shared kernel — every domain row carries `tenant_id`, enforced by a global scope + Postgres RLS (ADR 0011).
- **Catalog → Inventory** — a Variant holds stock per `(Variant, Location)`; a Bundle expands into component stock.
- **Catalog → Pricing** — a Listing-Variant resolves a List / Deal / Effective Price.
- **Orders → Inventory** — Order Lines RESERVE / SHIP / RELEASE stock (Bundles move their components).
- **Orders → Fulfilment** — ready-to-fulfil Orders are picked/packed in a Dispatch Round; fulfilment owns a local overlay (round / printed / internal note) on the otherwise read-only mirror Order.
- **Orders → Accounting** — a settled Order reconciles against its Accounting Entry across settlement cycles.
- **Orders → Claims** — a seller-fault Return or a shipping overcharge auto-flags a Claim.
- **POS** is a Shop of `platform_type = pos` that sells Variants directly (no Listing) and closes at the counter.
