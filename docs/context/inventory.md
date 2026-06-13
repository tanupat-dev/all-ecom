# Inventory

Stock as an append-only ledger (ADR 0003/0013): Locations, the On-Hand / Reserved / Available levels per (Variant, Location), Buffer, Oversell, and the Stock Movements that change them.

## Language

**Location**:
A physical place that holds stock — a storefront, stockroom, or warehouse — belonging to one Tenant. **Stock is tracked per `(Variant, Location)`**: On-Hand, Reserved, Available, Buffer and Oversell are all computed per Location, and a business-wide figure is the **sum** across Locations (see ADR 0013). Each Tenant auto-provisions **one default Location**, so a single-site seller never sees the concept — but the dimension exists from the start so it never has to be retrofitted. Each Shop is assigned a **fulfilment Location**: a marketplace Shop exports/decrements that Location's stock, and a `pos` Register sells from its store's Location. Moving stock between Locations is a **Transfer** — a linked **pair** of Stock Movements (out at source, in at destination; nothing created or destroyed).
_Also_: สาขา, คลัง, ที่เก็บของ, สถานที่
_Avoid_: Shop (a sales channel/account, not a physical place — many Shops may fulfil from one Location), Warehouse (only one kind of Location)

**On-Hand Stock**:
The physical quantity of a Variant present **at a Location** right now — what you can touch and count. Increases on `RECEIVE` (supplier delivery, returned goods after Inbound Scan), `RESTORE` (from Damaged), and `TRANSFER_IN`. Decreases on `SHIP` (goods leaving the building), `DAMAGE` (to Damaged Stock), and `TRANSFER_OUT` — each at a specific Location.
_Also_: ของจริงในร้าน, ของในมือ
_Avoid_: Stock (ambiguous now — use On-Hand / Reserved / Available)

**Reserved Stock**:
The quantity of a Variant earmarked for paid Orders that have not yet shipped — i.e., goods still physically in the shop but already "spoken for". Increases on `RESERVE` (Order enters `รอแพ็ค`). Decreases on `RELEASE` (Order `ยกเลิก` pre-pack) or `SHIP` (Order ships, which decrements On-Hand and releases the Reserved that Order held). **POS Orders never touch Reserved at all** — a walk-in sale is an immediate deduction (`รอชำระ → สำเร็จ` in one step, goods leave instantly), so it fires a single `SHIP` that cuts On-Hand only, with no prior `RESERVE` (industry-standard POS behaviour; reservation exists only for the fulfilment gap that marketplace/social Orders have and POS does not). **Pre-pack edits** to an Order's lines adjust Reserved by **appending** compensating movements (delta/compensation pattern, never mutating the ledger): +qty → extra `RESERVE`, −qty or removed line → partial `RELEASE`, swapped Variant → `RELEASE` old + `RESERVE` new. The trigger differs by channel — for **marketplace** Orders the edit is detected by the importer diffing re-imported Order Lines against stored ones (the buyer cancelled an item / reduced qty on the Platform); for **social/POS** Orders it is the seller's manual edit. Same RESERVE/RELEASE logic either way. Post-pack (Tracking exists) the lines are locked.
_Also_: ของจอง, ของที่จองแล้ว
_Avoid_: Allocated, committed (overloaded)

**Available Stock**:
The quantity of a Variant that is free to sell, **per Location**. Derived: `Available = On-Hand − Reserved − Buffer` for that `(Variant, Location)`. A marketplace Shop exports its **fulfilment Location's** Available; a `pos` Register sells against its Location's. For a **Bundle** there is no stored Available — it is derived `= min over components of floor(Available(component, Location) / qty)` (see Bundle). This is the number exported to Platforms via stock-update Excel. **Can go negative** — that is not a bug but the canonical Oversell signal: because sync is Excel-based (not real-time), two Platforms can sell the same last unit before the next stock export, so imported Orders legitimately reserve more than On-Hand. We never block such an import (the sale already happened on the Platform); negative Available surfaces it. When exported back to Platforms, a negative Available is clamped to `0`.
_Also_: ขายได้, พร้อมขาย
_Avoid_: Free stock, sellable stock

**Oversell**:
The situation where committed quantity (Reserved + shipped) for a Variant exceeds On-Hand — i.e. Available has gone negative — because Excel sync lag let multiple Platforms sell stock that wasn't really there. Buffer is the *preventive* layer (held back from exports to absorb lag); Oversell handling is the *corrective* layer. Our system does **not** prevent it (the order already exists on the Platform) and does **not** auto-cancel anything (cancelling carries Platform penalties — cancellation-rate fines, search-ranking drops, suspension — so it is the seller's call). Instead it raises an **Oversell alert** that:
- lists the affected Variant and the conflicting Orders, suggesting the **latest** Order(s) that pushed Available negative as cancel candidates (first-come-first-served — honour earlier Orders), while showing value, COD vs prepaid, age, and buyer so the seller decides;
- only Pre-Pack Orders can be cancelled.

Resolution is **import-driven** (consistent with marketplace Orders being read-only): the seller cancels on the **Platform** itself (our system cannot push a cancel up to the marketplace), then re-imports (on demand, not just the daily run) — the importer sees the Order as `ยกเลิก` and emits `RELEASE`, freeing the reservation so Available recovers. Alternatively the seller restocks fast to cover the gap.
_Also_: ขายเกิน, สต็อกติดลบ
_Avoid_: Stockout (that's simply zero stock, no conflict), backorder (we don't promise future fulfilment)

**Buffer**:
A quantity held back from Platform exports for a given Variant. If `On-Hand = 10`, `Reserved = 0`, `Buffer = 3`, every Platform sees `Available = 7`. Reduces oversell risk caused by import/export sync lag. Held per `(Variant, Location)` (default 0), applied uniformly across all Listings that draw on that Location.
_Also_: กันสต็อก
_Avoid_: Safety stock (overloaded — often implies reorder triggers), reserve (now means Reserved Stock specifically)

**Inbound Scan**:
A physical confirmation step where the seller scans a package upon physical arrival at the shop — used primarily to confirm that a returning Order (ตีกลับ / คืนสินค้า) has truly arrived, independent of what the courier system claims. Stock returns to the pool **only after** Inbound Scan, not when the platform/courier reports "returned to sender". The mechanism that lets the system detect courier reporting errors.
_Also_: สแกนรับของเข้าร้าน
_Avoid_: Receipt (overloaded with sales receipts), check-in

**Stock Movement**:
An immutable ledger entry recording one change to a Variant's stock state **at a Location**. Every change to On-Hand, Reserved, or Damaged Stock creates exactly one Stock Movement — current quantities are derived from (or denormalized against) the ledger, keyed `(tenant, variant, location)`. Each Movement records: `variant`, `location`, `action`, `qty_delta`, `timestamp`, `ref_type` + `ref_id` (Order / Stock Adjustment / null), `note`; a `SHIP` additionally records `reserved_released` — the reservation that Order actually held (marketplace/social = line qty, POS = 0) — so the ledger alone fully replays every pool. Ledger entries are **never updated or deleted** — corrections are made by appending new entries.

Action types (9):
- `RECEIVE` — goods in (supplier, returned Inbound Scan, manual receive); On-Hand +
- `SHIP` — goods out via Order; On-Hand − **always**, and Reserved − **only by the amount that Order actually reserved** (marketplace/social: the full line qty, since they `RESERVE` first; **POS: 0**, because a POS sale is an *immediate deduction* with no reservation step — so Reserved never goes negative)
- `RESERVE` — Order paid, awaiting pack; Reserved +
- `RELEASE` — Order cancelled pre-pack; Reserved −
- `DAMAGE` — flagged non-sellable; On-Hand −, Damaged Stock +
- `RESTORE` — Damaged judged sellable after all; Damaged Stock −, On-Hand +
- `RECOUNT` — manual recount delta; On-Hand ± directly (no other side)
- `TRANSFER_OUT` — leaving one Location for another; On-Hand − at the source Location
- `TRANSFER_IN` — arriving at a Location from another; On-Hand + at the destination Location (paired with a `TRANSFER_OUT`; the pair moves stock without creating/destroying it — see Location, ADR 0013)

_Also_: Stock Ledger, ledger entry, การเคลื่อนไหวสต็อก
_Avoid_: Transaction (overloaded — Order is also a transaction)

**Stock Return**:
The Stock Movement that credits goods back into a Variant after a return — always a `RECEIVE` action triggered by Inbound Scan of either a **Return Line** (`return_and_refund` Return; credits that line's `qty`) or, for a whole-Order `ตีกลับ`, the Order's lines. A `refund_only` Return never triggers Stock Return (no goods come back). The act of `ยกเลิก` pre-pack triggers `RELEASE`, not Stock Return (no physical goods were ever lost). Courier/Platform reports alone never trigger Stock Return.
_Avoid_: Restock (used for incoming supplier inventory), refund (that's the money side)

**Damaged Stock**:
Goods physically present in the shop but flagged by the seller as non-resellable — typically the result of a `DAMAGE` Stock Movement after a return Inbound Scan reveals damaged goods. Held in a separate pool from On-Hand Stock — visible for accounting/reporting but not counted toward Available Stock and never exposed to Platform exports. Items don't return to On-Hand automatically; a `RESTORE` Movement is required.
_Also_: สต็อกชำรุด
_Avoid_: Defective, broken

**Stock Adjustment**:
A manual change to On-Hand or Damaged Stock that is **not** driven by an Order — covers physical recounts, supplier deliveries, write-offs, and **inter-Location Transfers**. Each Adjustment produces one or more Stock Movements (`RECEIVE`, `DAMAGE`, `RESTORE`, `RECOUNT`, or a `TRANSFER_OUT`+`TRANSFER_IN` pair), each stamped with its Location. Captured via the system's own Excel import/export interface (separate from the Platform-facing Excel sync), so the seller can edit stock numbers in bulk offline.
_Also_: ปรับสต็อก
_Avoid_: Restock (subset — only supplier delivery case), correction (overloaded)

