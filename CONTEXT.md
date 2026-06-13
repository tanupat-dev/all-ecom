# CONTEXT.md → split into bounded contexts (see CONTEXT-MAP.md)

all-ecom's domain glossary outgrew one file, so the authoritative term definitions now live **per bounded context** under [`docs/context/`](./docs/context/), indexed by [**CONTEXT-MAP.md**](./CONTEXT-MAP.md). (Pattern: `mattpocock/skills` → grill-with-docs; multi-context repos use a Context Map.)

A code comment, ADR, or test that points to **`CONTEXT.md: <Term>`** still resolves — find the term's context below, then open that file.

| Context | Terms |
|---------|-------|
| [Tenancy & Access](./docs/context/tenancy.md) | Tenant · Platform · Shop · Shop Settings · User · Role · Permission · Audit Log |
| [Catalog](./docs/context/catalog.md) | Product · Variant · Bundle · Master SKU · Listing · Platform SKU · Channel Upload Template · Listing Coverage · Listing Status · Product Image |
| [Inventory](./docs/context/inventory.md) | Location · On-Hand Stock · Reserved Stock · Available Stock · Oversell · Buffer · Inbound Scan · Stock Movement · Stock Return · Damaged Stock · Stock Adjustment |
| [Orders](./docs/context/orders.md) | Order · Order Line · Order Status · Tracking Number · Return · Return Sub-Status · Refund Status · Cancellation Reason · Order Milestone Dates · delivered_date · completed_date · Payout Anchor |
| [Fulfilment](./docs/context/fulfilment.md) | Dispatch Round · Pick List · Dispatch Manifest · Printed Flag · Buyer Message · Internal Note |
| [Pricing & Promotions](./docs/context/pricing.md) | Promotion · Promotion Line · List Price · Deal Price · Effective Price |
| [Accounting & Profitability](./docs/context/accounting.md) | Cost Price · Platform Fee Profile · Accounting Line Category · Accounting Entry · Expense · Margin Calculator · Expected Net · Actual Net · Reconciliation Status · Hold Period · Expected Payout Date · Settlement Date · Mismatch Threshold |
| [Claims](./docs/context/claims.md) | Return Reason · Claim |
| [POS](./docs/context/pos.md) | Register · Shift · Paid-in / Paid-out · Payment · Receipt · POS Return · Manual Discount · Parked Sale |

> New terms go in the relevant `docs/context/<context>.md` (or a new context added to CONTEXT-MAP.md) — **not** here. This file is only a redirect for the many existing `CONTEXT.md: Term` pointers.
