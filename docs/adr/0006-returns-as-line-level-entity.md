# Buyer returns are a separate line-level entity, decoupled from Order Status

A buyer-initiated return after delivery is modelled as its own **Return** entity (header + Return Lines), attached to an Order — **not** as an Order Status value. One Order has zero or more Returns; each Return Line references one Order Line and a returned `qty`, so partial and repeated returns are first-class. The parent Order keeps its own lifecycle (it stays `สำเร็จ` while a return is in flight). Only `ตีกลับ` — the whole package failing delivery before the buyer keeps it — remains a whole-Order Closed-Failure status. Stock is credited per Return Line × qty on Inbound Scan, never per whole Order.

## Why

- **The platforms model it this way.** TikTok and Lazada both export returns with a `Return Order ID` distinct from the `Order ID`, per-SKU `Return Quantity`, and an order-level status that stays `Completed` while the return runs. A model that made `คืนสินค้า` a whole-Order status directly contradicts the source data — a buyer returning 1 of 2 Order Lines would wrongly flip the entire Order to a failure state and mis-credit stock.
- **Partial returns are common and money-critical.** Returns and Claims are the core value of this product (the user was burned by exactly this). Collapsing a partial return into a whole-Order status loses the per-line quantity that drives Stock Return, refund rollup, and `return_fee` Claims.
- **`refund_only` exists.** Platforms refund without goods coming back (TikTok "Refund only"). A Return needs a `return_type` so refund-only cases never touch stock — impossible to express cleanly if returns are an Order Status.

## Preventing dangling returns

A buyer can open a return and never ship it. We do **not** invent closure logic:

- **The Platform is the source of truth for closure** — each Platform auto-closes abandoned returns on its own timeout (TikTok: buyer ships within 5 days of seller approval or the case closes). We detect that closure on re-import (`Request Canceled` / `Refund rejected` / `ReturnClosed`) and move the Return to terminal `ยกเลิกการคืน` with no stock change — the same source-of-truth / fail-loud stance as ADR 0003 (Inbound Scan) and ADR 0005.
- **Stock can't be corrupted by a pending return**, because Stock Return fires only on Inbound Scan, never on a return status. A Return stuck in `รอผู้ซื้อส่งคืน` is at worst a stale row.
- **Stale-Return flag** — a Return past the Platform's buyer-ship window surfaces for the seller to re-import the latest return file or close manually.

## Consequences

- New `returns` / `return_lines` tables; `platform_return_id` is the dedup key for idempotent re-import.
- Refund Status becomes an Order-level **rollup** derived from the Order's Returns, rather than a directly-imported Order field.
- `return_fee` Claims now hang off a specific Return, not just the Order; `shipping_overcharge` Claims stay Order-level.
- Return Sub-Status is shared vocabulary used both on Return entities and on whole-Order `ตีกลับ`.
- More join complexity in reporting (Order → Returns → Return Lines), accepted as the cost of correctness.
