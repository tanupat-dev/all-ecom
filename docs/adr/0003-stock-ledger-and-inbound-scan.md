# Stock as a 3-quantity model on an immutable Movement Ledger, gated by Inbound Scan

Per Variant, Stock is exposed as three derived quantities: **On-Hand** (physical), **Reserved** (paid orders not yet shipped), and **Available** (`On-Hand − Reserved − Buffer`, exported to Platforms). Every change is recorded as one **Stock Movement** ledger entry (`RECEIVE` / `SHIP` / `RESERVE` / `RELEASE` / `DAMAGE` / `RESTORE` / `RECOUNT`); the ledger is append-only and corrections are appended, never updated. Goods returning from a buyer or courier do **not** credit Stock until the seller records an **Inbound Scan** confirming physical receipt — Platform and courier "delivered to seller" reports are explicitly distrusted.

## Why

- **Reserved vs On-Hand.** Sellers need to know what's physically in the shop (for packing) separately from what's free to sell (for platform exports). Collapsing into a single counter forces one or the other workflow to break.
- **Immutable ledger.** Stock disputes are common — "I thought we had 10, the system says 6, what happened?" — and only a full event log answers it. A live counter without history can be debugged only by guessing.
- **Inbound Scan as ground truth.** A prior system this user built marked returns as restocked when the courier said "delivered to seller," and they got burned when packages were lost in transit but counted as in-stock. The ground truth for "is the item back in my hands" is a physical scan, not a status from a third party.

## Consequences

- More moving parts than a single `stock_qty` column. Reads of Available are derived from On-Hand − Reserved − Buffer; writes hit the ledger and update denormalized counters atomically.
- Returns in `ตีกลับ` / `คืนสินค้า` show `ขนส่งแจ้งถึงร้านแล้ว` sub-status until scanned, surfacing courier reporting anomalies as a feature.
- Damaged goods live in a separate `Damaged Stock` pool — Inbound Scan is the moment the seller decides which pool the goods go into.
