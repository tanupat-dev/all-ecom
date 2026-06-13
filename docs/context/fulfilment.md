# Fulfilment

Turning imported Orders into shipped parcels: the Dispatch Round print-batch, its Pick List and Dispatch Manifest, and the local Printed Flag that is the 'already-handled' authority (ADR 0023).

## Language

**Dispatch Round**:
A **print-batch** of Orders a seller sends out together — the unit they pick, pack, and print documents for in one pass. Formed by **filtering the ready-to-fulfil pool** (Orders not yet physically shipped — phase `รอแพ็ค` or `แพ็คแล้ว` — that are **not yet printed**) across **one or many Shops** (a seller commonly ships several Shops in one trip), then confirming. Each Order belongs to **at most one** Dispatch Round (`round_id` — local and sticky); this membership, **not** the Platform Order Status, is the authority on "already handled", so a re-import that still shows the Order as `รอแพ็ค`/`แพ็คแล้ว` never pulls it back into the pool (see Printed Flag; ADR 0023). When volume is high a seller cuts **several smaller Rounds per day** rather than one. Each Round produces a **Pick List** and a **Dispatch Manifest**, printed together.
_Also_: รอบส่ง, รอบจัดส่ง
_Avoid_: Wave (the scheduling idea behind it), Batch (overloaded — import batches), shipment

**Pick List**:
The internal warehouse document of a Dispatch Round: **every ordered SKU aggregated into one total quantity** to pick in a single walk — date, line, SKU, item (brand/model/colour/size), quantity. **Channel-agnostic** — the same Variant sold across several Shops is one pick line (standard batch/cluster picking). Printed **together with** the Dispatch Manifest.
_Also_: ใบหยิบสินค้า, ใบหยิบของ
_Avoid_: Packing slip (the per-box customer doc), Dispatch Manifest

**Dispatch Manifest**:
The control/handover roster of a Dispatch Round: **one row per Order** for the seller to check off while packing and to hand to the courier — sequence, Order number, date, items, recipient name, province, Buyer Message, Internal Note, Shop, Tracking Number, shipping provider. Its **columns are seller-customisable** (a saved template per Tenant) and it exports to Excel to print/save as PDF. (Distinct from a Pick List, which aggregates SKUs, and from a per-box packing slip.)
_Also_: ใบคุม, ใบคุมส่ง
_Avoid_: Packing slip, Pick List, invoice

**Printed Flag**:
A **local, sticky** marker on each Order recording that its fulfilment documents have been printed (set when a Dispatch Round is created, or a filtered bulk-print runs). It — **not** the Platform Order Status, which can both **lag** behind and **jump ahead** of physical reality — is the authority for "done vs not done": the ready-to-fulfil pool excludes printed Orders, and re-importing a snapshot never clears it. Filterable (`ปริ้นแล้ว` / `ยังไม่ปริ้น`); re-printing an already-printed Order **warns first**. (See ADR 0023.)
_Also_: ปริ้นแล้ว, สถานะปริ้น
_Avoid_: Packed (a Platform Order Status), Fulfilled

**Buyer Message**:
The buyer's own note attached to an Order at checkout, carried **read-only** in the Platform order export — `หมายเหตุจากผู้ซื้อ` (Shopee), `Buyer Message` (TikTok); often a tax-invoice/receipt request. **Lazada has no such field** (buyers there use live chat, not an order remark), so it is left **blank** for Lazada Orders — fail-loud, never fabricated (ADR 0005). Distinct from the Platform's own seller-note column (unused/empty in exports) and from our **Internal Note**.
_Avoid_: Internal Note, Seller Note (the platform column), remark

**Internal Note**:
A free-text note a seller writes on an Order **in our system** — a packing instruction, or a buyer request that arrived via Platform chat (the practical stand-in where a Platform carries no **Buyer Message**, e.g. Lazada). **Locally owned** — never overwritten by re-import.
_Also_: หมายเหตุภายใน
_Avoid_: Buyer Message, remark

