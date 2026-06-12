# All-Ecom

A unified web app for small sellers to manage inventory, orders, accounting, and promotions across multiple e-commerce platforms (Shopee, Lazada, TikTok) plus a physical POS storefront. Designed for sellers who do **not** have marketplace API access — integration happens via Excel import/export. It is a **multi-tenant SaaS**: many independent seller businesses share one deployment, each fully isolated (see Tenant; ADR 0011).

## Tenancy

**Tenant**:
The top-level isolation boundary — one signed-up **seller business**. all-ecom serves many Tenants on one deployment, and everything else belongs to exactly one Tenant: its **Users** (with their own Roles), its **Shops** across platforms, its **Locations**, and its entire catalog, stock, orders, accounting, and promotions. No data is ever visible across Tenants. Isolation is **row-level**: every domain row carries `tenant_id`, enforced by *both* an application global scope *and* PostgreSQL Row-Level Security (defense in depth — a forgotten filter still can't leak one seller's data to another). "Unique per business" constraints (Master SKU, barcode) and the Roles & Permissions (see ADR 0012) are all scoped **per Tenant**. (See ADR 0011.)
_Also_: ธุรกิจ, ร้าน (the whole business, not one platform Shop), Seller Account, Organization
_Avoid_: Shop (one platform account *under* a Tenant — a Tenant has many Shops), User (a person within a Tenant), Business unit

## Language

**Platform**:
A sales channel. Each Platform has a `platform_type` that determines its sync behavior and capabilities:
- **`marketplace`** — Shopee, Lazada, TikTok. Excel/CSV import for Orders, Excel export for stock/promo updates. Has Tracking.
- **`social`** — LINE, IG, Facebook. No Platform export — sellers chat with buyers and **manually enter** Orders into our system. Has Tracking (normal courier shipping).
- **`pos`** — Physical storefront. Created via our checkout UI in real-time. **No Tracking**, instant lifecycle (รอชำระ → สำเร็จ in one step).

All three types share the same Order / Order Line / Stock model — they differ in **how Orders enter the system** and **which lifecycle states apply**.
_Also_: ช่องทาง
_Avoid_: Marketplace (only one of three types), site, channel (overloaded)

**Shop**:
A specific seller account on a Platform, **belonging to one Tenant**. One Platform can host multiple Shops (e.g., `tiktok1`, `tiktok2`) under the same Tenant, each potentially carrying a different (possibly overlapping) set of Products. Each Shop is assigned a **fulfilment Location** — the place its stock is drawn from / exported for (see Location); many Shops may share one Location.
_Also_: ร้าน
_Avoid_: Store, account, seller

**Shop Settings**:
The per-Shop configuration record — the single home for values that are tuned per Shop rather than globally or per Variant. One Shop Settings per Shop. Fields:
- `hold_period` — default days the Platform holds money before Settlement (see Hold Period; may auto-tune).
- `payout_anchor` — which Order Milestone Date the Hold Period counts from (`completed_date` for Shopee, `delivered_date` for TikTok/Lazada; see Payout Anchor).
- `mismatch_threshold` — ฿ tolerance before Reconciliation flips to `paid_mismatch` (see Mismatch Threshold).
- `expected_shipping_rate` — the weight-based courier rate(s) this Shop expects to pay, used to auto-flag `shipping_overcharge` Claims.

**Buffer is deliberately *not* here** — it is per `(Variant, Location)`, applied uniformly across all of a Variant's Listings that draw on that Location, so it lives with the Variant's per-Location stock, not on the Shop.

All four fields are **marketplace money-flow settings**; a `pos` Shop has no hold, payout, fees, or reconciliation, so they don't apply to it — a POS shop's operational config lives in its Registers and Shifts instead.
_Also_: ตั้งค่าร้าน
_Avoid_: Config (generic), preferences (user-level, not shop-level)

**Product**:
A model/รุ่น in the catalog — the parent record that groups Variants sharing the same name, images, description, and category. A Product on its own is not sellable; its Variants are. Roughly equivalent to a "product page" on a marketplace.
_Also_: สินค้า, รุ่น, Master Product
_Avoid_: Item, SKU (SKU is a Variant attribute), listing (a Listing is the Shop-side projection of a Product)

**Variant**:
The actual sellable unit — one specific combination of attributes (e.g., color + size) under a Product. Variant is the level at which Master SKU and List Price live; **Stock and Buffer are tracked per `(Variant, Location)`** (see Location). A Product with no real options still has exactly one default Variant.
_Also_: ตัวเลือก, รุ่นย่อย, SKU
_Avoid_: Option (means the attribute, not the combo), sub-product

**Bundle** (Kit):
A sellable Variant defined by a **BOM** — a list of component `(Variant, qty)` — instead of holding stock itself (see ADR 0014). In MVP a Bundle is **virtual**: it has **no On-Hand of its own**; its availability is derived `= min over components of floor(Available(component, Location) / qty)`. It lists and prices like any Variant (Master SKU, Platform SKU, List/Deal Price, Promotions). Selling a Bundle never moves "bundle stock" — at reserve/ship it **expands into its components**, emitting `RESERVE` / `SHIP` / `RELEASE` for each component × qty at the fulfilment Location, **atomically**. `COGS(bundle) = Σ component Cost Price (at sale date) × qty`. The pre-assembled "stocked kit" (its own On-Hand via an assembly step) is deferred — additive.
_Also_: เซ็ต, ชุดสินค้า, kit, BOM
_Avoid_: Variant (a Bundle is a *kind* of Variant but holds no stock — its components do), Product (the catalog parent, not a sellable set)

**Master SKU**:
The seller-defined, human-readable identifier for a Variant. Used in the UI, exports, and as the default Platform SKU. Free-form string — no hard-coded structure — but conventionally follows the seller's naming scheme (e.g., `brand-model-color-size`). **Unique per business** — one Master SKU identifies exactly one Variant; an attempt to assign a Master SKU that already exists is rejected/fail-loud (it would make the catalog ambiguous). (Distinct from Platform SKU, which is *not* one-to-one — a Variant may have several Platform SKUs.) Distinct from the internal numeric DB ID, which is never shown to users.
_Also_: รหัสสินค้า, Seller SKU (when used cross-platform consistently)
_Avoid_: Internal ID, master code

**Listing**:
A Product placed on a specific Shop. Carries Shop-specific attributes (display name, Deal Price, images, description, promotions) but draws from each Variant's shared On-Hand Stock and shared List Price. One Product → many Listings (one per Shop where it's offered). Each Listing maps its Variants to a **Platform SKU** — by default the Variant's Master SKU, but overridable if the seller had a different SKU on that platform pre-existing.

A Listing is the **channel-side projection layer** — it exists only for Shops that sell on an *external* channel needing its own SKU mapping, price, and attributes: i.e. **marketplace** Shops. A **`pos` Shop has no Listings**: the POS is the seller's own front-end on the master catalog, so it sells **Variants directly** (barcode / Master SKU → Variant, priced at the Variant's List Price + any Manual Discount). This follows the PIM standard — core product data lives in the master record, channel-specific overrides live in a separate linked structure only where a channel requires one — and ADR 0010 (no projection layer where there's no projection to manage).

**MVP scope (we are an OMS/inventory tool, not a listing/PIM tool):** a Listing stores only the **SKU mapping + Deal Price**, plus whatever per-platform fields an import file already hands us (category, image URL) kept **read-only for reference**. It is deliberately **not** a content-management layer — authoring/publishing per-channel images, descriptions, and attributes belongs to listing tools that push to the platforms via API (which we don't have), so that is out of scope and additive later.
_Also_: รายการขาย
_Avoid_: SKU listing, posting

**Platform SKU**:
The SKU code that a specific Platform/Shop uses for a Variant. Defaults to the Variant's Master SKU on Listing creation, but a Variant may carry **more than one** Platform SKU within the same Shop (the seller relisted it, or runs several listings/product-IDs for the same item). Used as the primary lookup key when importing orders: `(Platform, Shop, Platform SKU) → Variant`.

**Canonical mapping rule — this resolution must be a *function*, but need not be one-to-one:** a given `(Shop, Platform SKU)` always resolves to **exactly one** Variant; it never matters how many times that SKU appears or how many distinct SKUs point at the same Variant. So:
- the same SKU repeated across many Listings (same Shop) → one Variant ✅,
- many different SKUs → the same Variant (same Shop) ✅ (many-to-one),
- the same SKU on several Platforms/Shops → one Variant ✅,
- but the same `(Shop, Platform SKU)` pointing at **two different Variants** is the one illegal case — it breaks the function and is **fail-loud** (ADR 0005): the import surfaces the conflict for the seller to resolve, never guesses.

On import, the resolver consults a per-Shop `(Shop, Platform SKU) → Variant` map (populated from Listings); multiple listings sharing a SKU simply reinforce the same entry, a conflict flags it. On stock export, a Variant carrying several Platform SKUs in a Shop writes its Available to **each** of them (every listing reflects the one shared pool).
_Also_: Shop SKU, External SKU
_Avoid_: SKU (ambiguous), Listing SKU

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

**Tracking Number**:
The shipping label/tracking code generated by a Platform when the seller marks an Order as "ready to ship". The **presence** of a Tracking Number is the canonical marker that splits the Order lifecycle into **Pre-Pack** (no tracking yet, goods still in seller's hands, easy to cancel/edit) and **Post-Pack** (label printed, committed to shipping). Distinct from courier-internal IDs.
_Also_: เลขแทรค, เลข tracking, ใบปะหน้ากล่อง (the printed label that embeds it)
_Avoid_: AWB (courier-specific), shipping ID

**Inbound Scan**:
A physical confirmation step where the seller scans a package upon physical arrival at the shop — used primarily to confirm that a returning Order (ตีกลับ / คืนสินค้า) has truly arrived, independent of what the courier system claims. Stock returns to the pool **only after** Inbound Scan, not when the platform/courier reports "returned to sender". The mechanism that lets the system detect courier reporting errors.
_Also_: สแกนรับของเข้าร้าน
_Avoid_: Receipt (overloaded with sales receipts), check-in

## Orders

**Order**:
A purchase transaction originating from one Shop (or POS), captured into the system via Excel/CSV import (for marketplace Shops) or created directly (for POS/social). Has an Order Status, may have a Tracking Number (Post-Pack only), and contains one or more Order Lines. **Marketplace Orders are read-only mirrors** — the Platform is the source of truth, so changes never come from manual edits in our system but flow in via re-import (which reconciles Reserved Stock). Only **social/POS Orders are manually editable**, and only Pre-Pack.
_Also_: ออเดอร์, คำสั่งซื้อ
_Avoid_: Transaction, sale (overloaded), purchase

**Order Line**:
A single Variant + quantity within an Order. One Order may contain multiple Order Lines (different Variants in the same cart). Each Order Line is what actually deducts/returns Stock — not the Order as a whole. (An Order Line for a **Bundle** deducts/returns its **components'** stock, not the bundle itself — see Bundle.)
_Also_: รายการในออเดอร์
_Avoid_: Item, line item (less specific)

**Order Status**:
The canonical top-level state of an Order in our internal lifecycle, drawn from a fixed set of 8 values grouped into 4 phases:
- **Pre-Pack** (no Tracking Number yet): `รอชำระ`, `รอแพ็ค`
- **Post-Pack** (Tracking Number exists): `แพ็คแล้ว`, `กำลังขนส่ง`, `ถึงปลายทาง`
- **Closed-Success**: `สำเร็จ`
- **Closed-Failure**: `ยกเลิก`, `ตีกลับ`

Not every phase applies to every channel. A `pos` Order has **no Tracking**, so it never enters `รอแพ็ค` or any Post-Pack state — it uses only `รอชำระ` (while a Parked Sale waits before payment), `สำเร็จ` (closed at the counter), and `ยกเลิก` (a parked bill voided before payment); `ตีกลับ` is a marketplace/social courier-return state only. A POS Return is a separate negative-line Order, itself closing at `สำเร็จ` (see POS Return).

A buyer-initiated **return after delivery is NOT an Order Status** — it lives on a separate Return entity, because returns can be partial (one Order Line of many) and the parent Order legitimately stays `สำเร็จ` while a return is in flight (this matches how Shopee/Lazada/TikTok report it). Only `ตีกลับ` (the whole package failed delivery / bounced before the buyer kept it) is a whole-Order Closed-Failure state. (See Return; ADR 0006.)

Refund-state is tracked separately (see Refund Status) — it is not a top-level status. Each Platform's native status is **mapped** into this canonical set on import — we never store raw Platform statuses as the source of truth. The mapping is **fail-loud**: a native status with no entry in our mapping table is **never silently defaulted** to a canonical value — the import surfaces it as an explicit error ("unsupported status — ระบบไม่รองรับ") and the Order is held for the seller to resolve, so a Platform introducing a new status can't corrupt the lifecycle unnoticed.
_Also_: สถานะออเดอร์
_Avoid_: State (too generic), phase (refers to grouping, not the value)

**Return**:
A buyer-initiated return/refund case raised **after** the buyer has the goods — a separate entity attached to an Order, **not** an Order Status. **Marketplace (and social) buyer returns only** — a face-to-face **POS** return is *not* a Return; it is a linked negative-line Order (see POS Return; ADR 0009), because it has no authorization/in-transit gap to track. One Order can have **zero or more** Returns (partial and repeated returns are first-class). Modelled as **header + lines**, mirroring how Platforms export them (TikTok/Lazada both carry a `Return Order ID` distinct from the `Order ID`, with per-SKU return quantities):

- **Return header**: `platform_return_id` (the Platform's Return Order ID — the dedup key), `ref_order_id`, `return_type`, `return_reason` + buyer note, Return Sub-Status, `refund_amount`, return Tracking Number.
- **Return Line** (one or more): `ref_order_line_id` + `qty` — which Order Line and how many units of it are coming back. Stock Return credits stock per **Return Line × qty**, never the whole Order.

`return_type` (2):
- `return_and_refund` — goods come back **and** money is refunded. Triggers the Inbound-Scan → Stock Return journey.
- `refund_only` — money refunded with **no goods returned** (e.g. TikTok "Refund only"). **Never touches stock.**

Preventing dangling Returns (buyer requested but never shipped): the **Platform is the source of truth for closure** — each Platform auto-closes abandoned returns on its own timeout (TikTok: buyer must ship within 5 days of seller approval, else closed). We never invent our own closure logic; we **detect the Platform's closure on re-import** (`Request Canceled` / `Refund rejected` / `ReturnClosed`) and move the Return to `ยกเลิกการคืน`. Because stock only moves on Inbound Scan, a Return stuck in `รอผู้ซื้อส่งคืน` **cannot corrupt stock** — at worst it ages. A Return past the Platform's buyer-ship window surfaces a **stale-Return flag** prompting the seller to re-import the latest return file or close it manually. (See ADR 0006.)
_Also_: การคืนสินค้า, Return Order, เคสคืน
_Avoid_: Refund (that's only the money leg — a `refund_only` Return has no goods), คืนสินค้า as an Order Status (it no longer is one)

**Return Sub-Status**:
A second-level status tracking the **goods-coming-back journey**, gated by Inbound Scan. It lives on each **Return** entity (buyer-initiated, post-delivery) and is also used at Order level for an Order in `ตีกลับ` (whole-package failed delivery). Values:
- `รอผู้ซื้อส่งคืน` — buyer agreed to return but hasn't shipped yet *(Return entity only; this is the state the stale-Return flag watches)*
- `ขนส่งกำลังนำส่งกลับ` — courier picked up the return shipment, in transit
- `ขนส่งแจ้งถึงร้านแล้ว` — courier marked the package as delivered to seller, **but Inbound Scan not yet recorded** (the anomaly window — package may not have physically arrived)
- `รับของกลับแล้ว` — Inbound Scan recorded, package physically in hand, Stock Return triggered *(terminal — locks the Return/Order against revert)*
- `ยกเลิกการคืน` — Platform closed the case without goods being returned (Lazada `ReturnClosed`, TikTok `Request Canceled` / `Refund rejected`, buyer abandoned). No stock change. *(terminal)*

_Also_: สถานะการคืน
_Avoid_: Sub-state, return phase

**Refund Status**:
A read-only, Order-level **rollup** of refund state, derived from the Order's Returns (plus any Platform refund with no goods). The Platform processes refunds, we do not. Values: `ไม่มี`, `รอคืน`, `คืนเต็มจำนวน`, `คืนบางส่วน` — `คืนบางส่วน` is the natural rollup when some but not all Order Lines (or quantities) were refunded. Tracked independently of Order Status because refunds can occur with or without goods being returned.
_Also_: สถานะการคืนเงิน
_Avoid_: Payment status (refers to the original payment, not refunds)

**Cancellation Reason**:
Why and by whom an Order was cancelled — captured only when Order Status is `ยกเลิก`, distinct from Return Reason (cancellations happen before the buyer keeps the goods and **never feed Claims**). Stored as three fields, mirroring the Fee Category pattern (canonical bucket + raw source + fail-loud):
- `cancelled_by` — `Seller` / `Buyer` / `System`. The attribution that matters: **Seller cancellations count against the Platform's Cancellation Rate** (fines, search-ranking drops, suspension), so this is the field the seller monitors.
- `cancel_reason_category` — a small canonical bucket (Goldilocks — few, not many): `out_of_stock`, `pricing_error`, `buyer_changed_mind`, `address_change`, `payment_issue`, `failed_delivery`, `other`. Reason **codes**, not free text, so they can be totalled and trended.
- `cancel_reason_source` — the Platform's raw reason text, kept for drilldown.

Per-Platform extraction differs: **TikTok** gives `Cancel By` + `Cancel Reason` as two columns; **Shopee** bundles both into one string (`"ยกเลิกโดย{ผู้ขาย/ผู้ซื้อ/ระบบ} เหตุผล : …"`, with stray `<br>` in system-cancel text) that the importer must parse; **Lazada** often exposes no reason (`cancelled_by`/category null). Mapping is fail-loud (ADR 0005): an unrecognised reason reaches `other` only via an explicit mapping entry, never an automatic fallback. Primary use: the **Seller Cancellation Rate report**, watching `out_of_stock` cancels as the downstream signal of Oversell.
_Also_: เหตุผลการยกเลิก, สาเหตุยกเลิก
_Avoid_: Return Reason (post-delivery, feeds Claims — different concept), cancel note

**Order Milestone Dates**:
A fixed set of nullable timestamp fields on each Order, one per canonical lifecycle event, filled from the matching column in each Platform's order export: `created_date`, `paid_date`, `shipped_date`, `delivered_date`, `completed_date`, `cancelled_date`. They are the timestamp companions to Order Status (each records *when* the Order reached that point). Populated by upsert on every import and never null-overwritten, so daily snapshot imports that skip intermediate statuses still capture every milestone — because the export carries cumulative timestamps as columns, not as an event stream. (See ADR 0004.)
_Also_: วันที่ของออเดอร์
_Avoid_: Status history (we keep timestamps, not a transition log), event log

**delivered_date**:
The Order Milestone Date marking when goods physically arrived at the destination — i.e., the Platform reported `ถึงปลายทาง` (delivered). Distinct from `completed_date`. Available as a timestamp column on **TikTok** (`Delivered Time`) and **Lazada** (`deliveredDate`); on **Shopee** delivery is only knowable from a status string (no timestamp column), so `delivered_date` is typically null for Shopee Orders.
_Also_: วันที่ส่งถึง
_Avoid_: Completed date (that's a later, separate event), arrival date

**completed_date**:
The Order Milestone Date marking when the Order was **finalised** — the buyer pressed "received" or the auto-confirm/return window expired (`สำเร็จ`). Strictly *after* `delivered_date`: it is delivered **plus** the Platform's buyer-confirm/return window. Available as a timestamp column on **Shopee** (`เวลาที่ทำการสั่งซื้อสำเร็จ`); on **TikTok** completion is knowable only from status (`เสร็จสมบูรณ์`, no timestamp column), and **Lazada** exports do not expose it.
_Also_: วันที่ปิดออเดอร์, วันที่สำเร็จ
_Avoid_: Delivered date (earlier, separate event), closed date

**Payout Anchor**:
A per-Shop setting naming which Order Milestone Date the Platform's payout clock counts from — the timestamp fed into `expected_payout_date = anchor + hold_period`. Each Platform conveniently supplies a timestamp column for exactly the milestone its own payout anchors on: **Shopee → `completed_date`**, **TikTok / Lazada → `delivered_date`**. A misconfigured anchor silently shifts every Order's expected payout date.
_Also_: จุดเริ่มนับเงินเข้า
_Avoid_: Payout date (that's the result), settlement anchor

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

**Return Reason**:
The platform-supplied field in a return/refund export indicating why the buyer requested a return — stored on the **Return** header. Classified into two buckets for Claim auto-flagging. Classification is **fail-loud**: a reason text not found in the platform's known list is **not** silently bucketed (neither buyer- nor seller-fault) — it is surfaced as "unrecognised reason — ระบบไม่รองรับ" for the seller to classify manually, so a platform changing/adding reason texts can't silently mis-flag Claims.

- **buyer-fault** — buyer changed mind, no longer needed, return in perfect condition. No Claim implications.
- **seller-fault** — received wrong item, damaged, doesn't match description, incomplete, not received. Triggers Claim auto-flagging.

Each platform stores reasons differently:
- **Shopee**: static text. buyer-fault = `ฉันต้องการคืนสินค้าในสภาพสมบูรณ์` only. All other codes (สินค้าหมดอายุ, ทำงานไม่สมบูรณ์, แตกหัก, รอยขีดข่วน/บุบ, ความเสียหายอื่นๆ, ได้รับสินค้าผิด, สินค้าแตกต่างจากที่สั่ง, ไม่ได้รับพัสดุ, สินค้าไม่ครบ/ชิ้นส่วนไม่ครบ, กล่องเปล่า) = seller-fault.
- **TikTok**: static text. buyer-fault = `ไม่ต้องการอีกต่อไป` / `No longer needed`. seller-fault = `สินค้าไม่ตรงกับคำอธิบาย`, `สินค้าไม่ถูกต้อง/ส่งสินค้าผิด`, `สินค้ามีตำหนิหรือใช้งานไม่ได้`, `ได้รับพัสดุแต่มีสินค้าขาดหาย`, `พัสดุหรือสินค้าเสียหาย`, `ไม่ได้รับพัสดุ`, `สงสัยว่าเป็นของปลอม`, `สินค้าหมดอายุ`, `บรรจุภัณฑ์เสียหาย`. Pre-shipment cancellation reasons that appear in the same file but are NOT returns (no Claim logic): `สินค้ามาถึงไม่ตรงเวลา`, `ต้องการเปลี่ยนวิธีการชำระเงิน`, `จำเป็นต้องเปลี่ยนที่อยู่จัดส่ง`, `มีราคาที่ดีกว่า` — importer must skip Claim flagging for these.
- **Lazada**: static English text (current list as of 2026). buyer-fault = `Change of mind` only. seller-fault = `Expired items`, `Missing items in the parcel delivered`, `Item physically damaged upon opening parcel`, `Outer Packaging of the item is damage`, `Item size is not advertised`, `Missing accessories or freebies`, `Counterfeit items`, `Item is defective or not working as intended`, `Wrong items delivered`, `Item/ quality doesn't match description or pictures`. ⚠️ Thai-language version of this list not yet confirmed — verify at importer build time. Older reason texts (from pre-2021 exports) are stale and should not be used for classification.

⚠️ Buyers sometimes choose a seller-fault code when the fault is their own (e.g., "ได้รับสินค้าไม่ตรง" when they ordered the wrong size). The auto-flag is a prompt for the seller to verify — not a confirmed finding of fault. The system must display Return Reason and Buyer Note side-by-side to help the seller assess whether a Claim is warranted.
_Also_: เหตุผลในการขอคืนสินค้า
_Avoid_: Cancellation reason (separate field on Shopee cancelled orders, different concept)

**Claim**:
A request the seller files with a Platform to recover money the Platform or its courier deducted incorrectly. Our system **does not file** Claims; it scaffolds the work around them. ⚠️ Claim flows differ per Platform — must be researched per importer.

Two `claim_type`s:
- **`return_fee`** — attaches to a specific **Return** whose Return Reason mapped to the seller-fault bucket on import, prompting the seller to verify whether they actually shipped correctly. If yes → file Claim to recover return shipping + deductions. If the seller confirms it was their own fault → close without claiming.
- **`shipping_overcharge`** — attaches to the **Order**; courier charged more than the expected weight-based rate. Recover the excess. Auto-flagged from Accounting Entry analysis when `shipping_seller_paid` exceeds the expected rate (`expected_shipping_rate` in Shop Settings).

A Claim attaches to one Order (a `return_fee` Claim additionally to the specific Return that triggered it) and tracks three things:

1. **Claim Status** (6 values, two-stage lifecycle):
   - `eligible` — auto-flagged on import when return reason ≠ "เปลี่ยนใจ/ไม่ต้องการ"; nothing submitted yet
   - `submitted_initial` — first-round Claim filed with Platform, awaiting decision (e.g., TikTok's initial claim flow — no chance to add evidence at this stage; evidence must be ready beforehand)
   - `submitted_ticket` — initial Claim rejected, seller opened a support ticket as stage 2; Platform may request additional evidence within the ticket
   - `approved` — won at any stage *(terminal)*
   - `rejected` — lost at final stage or seller chose not to escalate to ticket *(terminal)*
   - `abandoned` — manually closed without resolution *(terminal)*

2. **Evidence Checklist**: structured proof items the seller collects. Default items: outgoing packing/shipping video, incoming unboxing video, weight on scale (before/after), photos of received goods. Seller can extend per Claim. Most critical *before* `submitted_initial` since some Platforms (TikTok) don't allow adding evidence post-submission.

3. **Claim Timeline**: append-only log of manual entries (date, action, note, optional ticket #) — submission, decisions, info requests in ticket stage, evidence updates, payout amounts.

_Also_: เคลม, เคลมแพลตฟอร์ม
_Avoid_: Refund (Refunds flow Platform→Buyer; Claims flow Platform→Seller), dispute (broader), case (overloaded)

## Promotions

**Promotion**:
A pricing rule that lowers the effective price of one or more Variants on one or more Listings. Two types coexist under one model — distinguished by the `type` field and the presence of a time window:

- **`base`** — the seller's regular markdown from List Price. No `end_at` (always active). At most **one active `base` Promotion per Shop**. This is what marketplace platforms call "Product Discount" — what the platforms display as the strikethrough Selling Price.
- **`campaign`** — a time-bounded extra discount on top of `base`. Has `start_at` and `end_at`. Used for flash sales, monthly events (6.6, 7.7), etc.

**MVP rule:** At any time T, a Listing-Variant has **exactly one active Promotion Line** — either the base Promotion's line, or one campaign Promotion's line if a campaign is currently in window. No overlapping campaigns on the same Listing-Variant.

A Promotion contains one or more **Promotion Lines** (one per Variant × Listing), each carrying its own `deal_price`. One Promotion may span multiple Shops/Platforms — the user picks which Listings to include when building the Promotion. *(Per-buyer / total quantity limits are deferred post-MVP — Promotion Line carries no limit fields for now.)*

**Effective Price** of a Listing-Variant at time T:
```
if active campaign Promotion Line at T  → its deal_price
else if base Promotion Line             → its deal_price
else                                    → List Price
```

System emits **expiry reminders** when an active campaign approaches `end_at`.

_Also_: โปรโมชั่น
_Avoid_: Discount (generic), sale (overloaded), markdown (used informally for the base type only)

**Promotion Line**:
A single row of a Promotion: one Variant on one Listing (i.e. Variant × Shop), carrying its own `deal_price`. The unit at which a discount is actually applied and exported — every platform's discount/product file is one row per Variant on one Shop, which maps exactly to one Promotion Line. At any time T a given Listing-Variant has **exactly one active Promotion Line** (the base Promotion's, or one campaign's if in window) — this is what Effective Price resolves against. On export the Promotion Line's `deal_price` becomes the platform's discount field (Shopee `ราคาส่วนลด`, TikTok `Deal Price`, Lazada `SpecialPrice`). Carries no purchase-limit fields in MVP.
_Also_: รายการโปรโมชั่น, บรรทัดโปร
_Avoid_: Promotion item, discount line (vague), promo row

**List Price**:
The original "ป้ายราคา" of a Variant — the un-discounted reference price the strikethrough on the listing comes from. Lives on the **Variant**: a single value shared across all of that Variant's Listings and **identical on every Platform**. Per-Platform price differences are expressed through Deal Price, never through List Price. Edited once per Variant (incl. via batch Excel) and applies everywhere. Never directly the price a buyer pays unless no Promotion is active.
_Also_: ราคาป้าย, ราคาเต็ม
_Avoid_: Original price, MSRP

**Deal Price**:
The seller's promoted price per unit for a Variant under an active Promotion Line. Always stored as a Thai Baht amount — even if the seller entered a `% off`, the system converts and stores Deal Price. The format all Platform Excel exports expect.
_Also_: ราคาส่วนลด, ราคาขาด
_Avoid_: Sale price, discount (the action, not the value)

**Effective Price**:
The price the seller actually charges for a Variant on a Listing at a given point in time — the anchor for all financial calculations. Derived: Deal Price if an active Promotion Line exists, otherwise List Price. Corresponds to `ราคาขาย` in platform order exports. Platform-funded buyer subsidies (coupons the platform pays for) reduce what the buyer pays but do **not** reduce the Effective Price — fees are always calculated on Effective Price, and Actual Net = Effective Price minus all platform fees.
_Also_: ราคาขาย
_Avoid_: Selling price (vague), buyer price (that's what the buyer paid, which may differ due to platform subsidies)

## Accounting & Profitability

**Cost Price**:
The seller's cost to acquire one unit of a Variant — used for profit calculation. Stored with **change history**: each time the seller updates the cost, the previous value is preserved with a `valid_from` timestamp. When computing the profit of a past Order, the system uses the Cost Price that was active on the Order's sale date — not the current cost.
_Also_: ราคาต้นทุน, ทุน
_Avoid_: COGS (a derived per-Order amount, not the per-unit value), purchase price

**Platform Fee Profile**:
The expected/predicted fee rates the system applies when **estimating** the seller's net receivable on a hypothetical sale — used by the Margin Calculator to recommend a Selling Price for a desired profit. Configured per Platform (optionally per Shop or per Variant category). Distinct from Accounting Entries which record what was *actually* charged. May be auto-suggested from historical Accounting Entry averages.
_Also_: ค่าธรรมเนียมคาดการณ์
_Avoid_: Rate card (platform-published, may differ from reality), commission rate (only one of several fields)

**Fee Category**:
The canonical bucket for a single platform-fee component when imported from Accounting Excel. **8 categories** — extensible:
1. `commission` — platform commission + service fee + infrastructure fee (the "platform take")
2. `payment_fee` — payment processing
3. `shipping_seller_paid` — outbound shipping net to seller (after Platform subsidy)
4. `shipping_return` — return shipping deducted from seller
5. `marketing_fee` — campaign/flash sale fees, ad spend (GMV Max, Xtra), coupons funded by seller
6. `affiliate_fee` — paid out to affiliates/partners (TikTok-prominent)
7. `tax_withheld` — withholding tax, VAT
8. `other` — catch-all

Each Accounting Entry maps one Platform-native fee field to one Category. Signed amount (`+` = seller receives, `−` = seller pays) preserved. The Platform-native field name is kept in `source_field` for drilldown.
_Also_: หมวดค่าธรรมเนียม
_Avoid_: Fee type (too generic)

**Accounting Entry**:
The complete financial record for one Order, built from a Platform's accounting Excel. Contains all fee and income line items for that Order — each line item has a `category` (Fee Category), `amount` (signed THB, `+` = seller receives, `−` = seller pays), `source_field` (Platform's original column name), and the `statement_cycle` it came from. Always attached to one Order (`ref_order_id` is required, never null). **Marketplace Orders only** — an Accounting Entry exists to capture the platform's fees and its settle-later money flow (the gap that needs reconciling). A **POS Order has no Accounting Entry**: its money is collected in hand at the point of sale with no platform fees, so its contribution to the P&L is computed **directly** — revenue = the Payment total, COGS = the Cost Price of the Variants sold (recognised at the same moment, per the matching principle), no fee leg. The combined P&L sums a per-Order net across channels (marketplace = Actual Net from the Accounting Entry; POS = Payment − COGS). Each Platform structures its accounting file differently — one row per Order (Shopee wallet), multiple rows per Order (Lazada transaction journal), or one wide row with many fee columns (TikTok) — the importer normalises all into line items under one Accounting Entry per Order.

**Import is cycle-aware, not "immutable once imported"** (see ADR 0007): accounting files are issued per **statement cycle** (`รหัสรอบบิล` / settlement period), and an Order's line items can be split across several cycles — e.g. the sale posts in one cycle, a return deduction in a later one. So re-importing the **same cycle** replaces that cycle's line items for the Order (idempotent — no double-count), while a **new cycle appends** its line items. The Accounting Entry's totals (and Actual Net) are the sum across **all** of the Order's cycles.
_Also_: รายการบัญชี
_Avoid_: Transaction (overloaded with Order/Stock Movement), journal entry (accounting jargon)

**Expense**:
Money the seller spends on something that is **not** Cost Price and **not** a Platform fee — i.e., operating expenses entered manually by the seller. Has `date`, `category` (free-form: packaging, free gifts, paper, utilities, rent, staff, etc.), `amount`, `note`, and optional `ref_order_id` (for per-order attributable costs like a free gift sent with a specific Order). Used in period-level (monthly) P&L. *MVP focuses on non-attributable Operating Expenses; per-Order packaging cost allocation is a later refinement.*
_Also_: ค่าใช้จ่ายร้าน, OPEX
_Avoid_: Cost (means Cost Price), spending (vague)

**Margin Calculator**:
A tool that, given Cost Price + target profit (as `%` or fixed THB) + Platform Fee Profile, computes the **Effective Price** the seller must set to achieve the target after fees. Symmetric: given an Effective Price, shows the implied profit. Operates per Listing-Variant because different Platforms have different fee structures → different recommended prices.

Formula direction (target → price):
```
required_net = cost + target_profit
effective_price = required_net / (1 − total_fee_rate)
```
_Also_: คำนวณราคาขาย
_Avoid_: Price suggester (vague)

**Expected Net**:
The Effective Price net of Platform fees the seller *expects* to receive for an Order — derived from Effective Price minus the Platform Fee Profile applied to that sale. The forward-looking number used to set prices and to be checked against reality.
_Also_: เงินที่คาดว่าจะได้
_Avoid_: Net revenue (more ambiguous)

**Actual Net**:
The Effective Price net of Platform fees the seller *actually* received for an Order — the total of all signed amounts in the Order's Accounting Entry. The backward-looking number that grounds the Expected Net check.
_Also_: เงินที่ได้จริง
_Avoid_: Realized revenue, payout (overloaded)

**Reconciliation Status**:
A per-Order flag comparing Expected Net to Actual Net — **marketplace Orders only**. A POS Order has no Reconciliation Status: the money is already in hand at the sale (no hold, no settlement, nothing to reconcile). Three values:
- `not_yet_paid` — no Actual Net yet (Order still within Platform hold period, or accounting Excel not yet imported)
- `paid_ok` — Actual Net imported, |Actual − Expected| ≤ Mismatch Threshold
- `paid_mismatch` — Actual Net imported, |Actual − Expected| > Mismatch Threshold; surfaces in a Mismatch list for investigation, with auto-suggestion of a Claim type if the pattern matches

There is intentionally **no `Payout` entity** in the model — Wallet→Bank withdrawals are seller-controlled cash flow, not a source of reconciliation error. (Future: a manual "verified" checkbox on bulk withdrawals if needed.)
_Also_: สถานะตรวจสอบ
_Avoid_: Settlement status (overloaded), payment status

**Hold Period**:
The number of days the Platform typically holds the Actual Net before crediting the seller's wallet — used to set the `expected_payout_date` of an Order (`payout_anchor_date + hold_period`, where the anchor is the milestone named by the Shop's Payout Anchor — `completed_date` for Shopee, `delivered_date` for TikTok/Lazada) so the system knows when to expect reconciliation. Configured per Shop with a default value, and auto-tuned over time from historical median (`payout_anchor_date → settlement_date`) once Accounting Excel has been imported repeatedly — but only where the Platform exposes a Settlement Date; otherwise the manual value stands.
_Also_: ระยะเวลาถือเงิน, hold time
_Avoid_: Settlement period (more specific accounting term)

**Expected Payout Date**:
The date the system predicts an Order's money will be settled into the seller's Platform balance — derived as `payout_anchor_date + hold_period` (from Shop Settings). Its job is not display but **detection**: once this date passes and no Settlement Date has been imported for the Order (Reconciliation still `not_yet_paid`), the Order is **overdue** and surfaced so the seller can chase the money or file a Claim **before the Platform's claim window closes**. A null `payout_anchor_date` (goods not yet delivered/completed) means no Expected Payout Date yet.
_Also_: วันคาดว่าเงินเข้า, กำหนดเงินเข้า
_Avoid_: Payout date (that's the actual event), due date (vague)

**Settlement Date**:
The date the Platform **released an Order's money from hold into the seller's withdrawable Platform balance** — i.e. the moment the funds became the seller's, read from the accounting Excel. **Not** the date the money was withdrawn from the Platform into the seller's bank account: that withdrawal is seller-controlled cash flow (manual on TikTok, automatic on Shopee/Lazada) and is deliberately ignored — consistent with there being no Payout entity. Used to (1) auto-tune Hold Period (median of `payout_anchor_date → settlement_date`) and (2) confirm money actually arrived for Reconciliation. Availability differs per Platform: Lazada exposes it (`วันที่ปรับปรุงเข้ายอดของฉัน`), Shopee in its wallet/income report; **TikTok's export may not contain it at all** — when absent, auto-tune is disabled for that Shop and the manually-set `hold_period` is used instead (we never guess the date — see ADR 0005).
_Also_: วันเงินเข้ายอด, วันปล่อยเงิน
_Avoid_: wallet_credit_date (conflated with bank withdrawal), payout date, withdrawal date (that's the ignored Wallet→Bank step)

**Mismatch Threshold**:
The absolute Thai Baht amount within which Actual Net and Expected Net are considered equal — set per Shop, default ฿1 to absorb rounding. Differences above this threshold flip Reconciliation Status to `paid_mismatch`.
_Also_: เกณฑ์รับได้
_Avoid_: Tolerance (vague)

## Access

**User**:
A login identity for a person who operates the system. Belongs to exactly one **Tenant** (not scoped to a single Platform or Shop within it). Every User holds **one or more Roles**, and many Users may share a Role — there can be **multiple Admins and multiple Cashiers** per Tenant. The User is what actions are attributed to — most importantly, which Cashier opened/closed a POS **Shift**, since cash over/short is that person's responsibility. Distinct from **Buyer** (the customer who places an Order) and from **Shop** (a sales-channel account, not a person).
_Also_: ผู้ใช้, พนักงาน
_Avoid_: Account (overloaded — Shop is a platform account; Buyer is the customer), staff (a Cashier is staff but Admin may be the owner)

**Role**:
A named, **Tenant-defined** set of Permissions assigned to Users (see ADR 0012, superseding the fixed-two-role model of ADR 0008). Each Tenant **creates, edits, and deletes its own Roles**, choosing by checkbox which Permissions each Role grants — so a business can shape access to its own org (a "ผู้จัดการ" who sees reports but not cost; a "คลัง" who edits stock only; etc.). A User holds one or more Roles; their effective access is the **union** of those Roles' Permissions. Every Tenant is seeded with two **editable default Roles** — **Admin** (all Permissions) and **Cashier** (the POS subset) — so it works out of the box; the old admin/cashier split lives on only as these defaults. Roles are scoped **per Tenant** and never shared across Tenants. Deleting a Role that is in use first strips it from the Users holding it (warned, never silently orphaned). Safeguard: no create/edit/delete may leave the Tenant with **no User able to manage Users and Roles** (no lock-out).
_Also_: บทบาท, สิทธิ์, ตำแหน่ง
_Avoid_: Permission (a Role is a *bundle* of Permissions, not one capability), fixed role (Roles are custom per Tenant now), Cashier as a separate entity (a default Role, not its own table)

**Permission**:
A single granular capability the system checks before allowing an action — the unit a Role is composed from. Permissions are granular at **(area × action)** so that *access* and *edit* are separable: a Role can be granted **view** of an area without **edit** ("เข้าถึงได้แต่แก้ไม่ได้"). Each data area therefore has view / create / edit / delete Permissions, plus custom action Permissions for special operations. The **catalogue of Permissions is system-defined** — the application ships the list, because every Permission must correspond to a real check in the code; Tenants compose Permissions into Roles but cannot invent new ones. It grows as features ship. Representative Permissions: `product.view` / `product.edit`, `stock.view` / `stock.adjust`, `order.view` / `order.import`, `accounting.view` / `accounting.manage`, `report.view`, `cost.view` (the cost/COGS/margin visibility gate), `pos.checkout`, `pos.open_shift`, `sale.void`, `sale.refund`, `sale.discount`, `user.manage`, `role.manage`. The access controls described elsewhere in this glossary (cost visibility, void/refund/discount gating) are all expressed as Permissions.
_Also_: สิทธิ์การเข้าถึง, capability
_Avoid_: Role (a Role groups Permissions), Feature flag (toggles a feature for everyone, not a per-Role grant)

**Audit Log**:
An **append-only** record of who approved an admin-gated action (void, refund, manual discount, …) — one entry per approval, carrying the action name, the approving User, the affected record, and optional detail. Follows the system's ledger pattern: an entry is **never updated or deleted**. This is the Phase-0 audit rule ("admin-gated actions must log who approved") made concrete; every gated action records its approval here in the same flow that performs it.
_Also_: บันทึกการอนุมัติ
_Avoid_: Activity log (general user activity tracking — this records *approvals of gated actions* only), History (vague)

## POS

**Register**:
A physical checkout point (counter / till) inside a `pos` Shop — the thing a Shift is opened on. **Fixed-till** model: a Shift belongs to one Register and is never moved between Registers. Fields: `ref_shop_id` (must be a `pos` Shop), `name`, `active`. A `pos` Shop **auto-provisions one default Register** on creation, so a single-counter shop never has to think about Registers; adding/naming a second counter is a later UI affordance, but the model carries Register from day one so the "one open Shift per Register" rule never has to be retrofitted.
_Also_: เครื่องขาย, จุดชำระเงิน, เคาน์เตอร์, till
_Avoid_: Terminal (hardware-specific), POS (that's the Platform type, not one counter), drawer (the cash drawer is part of a Register, not the Register itself)

**Shift**:
One Cashier's session at one Register, from open to close — the **unit of cash accountability** (whoever opened the Shift owns its over/short). **Invariant: at most one *open* Shift per Register at a time** (so multiple Registers can each have an open Shift simultaneously). A POS Order can only be rung when a Shift is open on that Register, and each POS Order is **attributed to the Shift** (hence to the Cashier). Fields:
- `ref_register_id`, `cashier` (the User who opened it), `opened_at`, `closed_at`, `status` (`open` / `closed`).
- `opening_float` — counted starting cash declared at open (the baseline for reconciliation).
- `counted_cash` — the cash the Cashier physically counts at close. Entered **blind**: the Cashier counts *before* the system reveals what it expected (anti-fudging).
- `expected_cash` — derived = `opening_float + cash sales (net of change given) + paid-in − paid-out − cash refunds`. (Cash sales = the `cash`-tender Payment Lines on the Shift's Orders, less change handed back — see Payment.)
- `over_short` — `counted_cash − expected_cash`; surfaced on the Shift report, the figure management watches. It posts to the P&L as a **Cash Over/Short** line (the standard income-statement account): a net shortage is an other-expense, a net overage is other-income.

**Paid-in / Paid-out**: cash added to or removed from the drawer for a non-sale reason (making change, a supplier cash payment, a bank drop). Each is recorded on the open Shift **before** the cash physically moves, so `expected_cash` stays truthful. (Modelled as lightweight cash movements on the Shift, distinct from Stock Movements.)
_Also_: กะ, รอบขาย, เปิด-ปิดกะ
_Avoid_: Session (too generic), batch (payment-processor term for settling card transactions — different concept), Z report (that's the *printout* of a closed Shift, not the Shift)

**Payment**:
How money was collected for a **POS** Order — modelled as one or more **Payment Lines** so a single Order can be settled with **split tender** (e.g. part cash, part transfer). **POS-only**: marketplace money is tracked via the Accounting Entry / Actual Net, and social Orders in MVP are simply assumed paid — neither uses Payment. Each Payment Line carries `tender_type` and `amount`. `tender_type` (4): `cash`, `promptpay_qr`, `bank_transfer`, `card`.

- The Payment Lines' total must be **≥** the Order total; any excess is **change** — only `cash` gives change.
- **All tenders are cashier-confirmed manually** in MVP — the Cashier eyeballs the QR/transfer/card result and marks it received. There is **no automatic verification** (no payment-gateway webhook, no bank/slip-verification API). Auto-confirmation — a slip-verification API (e.g. SlipOK-style, checking the slip against the Bank of Thailand; the preferred future path because it keeps the seller's own PromptPay and adds no settlement layer) or a dynamic-QR gateway — is **deferred post-MVP**.
- `cash` Payment Lines feed the open Shift's `expected_cash` (and change reduces net cash in the drawer); non-cash tenders do not affect the cash reconciliation.
_Also_: การชำระเงิน, รับเงิน, ช่องทางจ่าย
_Avoid_: Tender as a separate entity (a tender is a `tender_type` on a Payment Line, not its own table), Settlement (that's the Platform releasing marketplace money — see Settlement Date), COD (a marketplace/social concept, not a POS tender)

**Receipt**:
The proof-of-payment document handed to a walk-in buyer when a POS Order closes. In MVP this is a plain **ใบเสร็จรับเงิน** (receipt) — **not** a tax invoice. It is **not its own entity**: it is *rendered* from the POS Order + its Payment Lines (items, totals, tender breakdown, change, shop info, optional embedded PromptPay QR), and is reprintable. The only new field it requires is a `receipt_no` on the POS Order — a running number assigned at close (`สำเร็จ`), sequential per `pos` Shop.

**Why plain receipt only:** the target sellers are **not VAT-registered** (annual revenue under the ฿1.8M threshold), and Thai law **forbids a non-VAT business from issuing any tax invoice**. So the VAT documents are deliberately out of MVP and **deferred** until VAT-registered sellers are supported:
- **ใบกำกับภาษีอย่างย่อ (ABB)** — what a VAT-registered retail shop issues at the counter; needs the "ใบกำกับภาษีอย่างย่อ/TAX INV(ABB)" mark, 13-digit tax ID, a legally-compliant running number, and "ราคารวมภาษีมูลค่าเพิ่มแล้ว"; the POS machine issuing it needs Revenue Department approval.
- **ใบกำกับภาษีเต็มรูป** — full tax invoice on buyer request (captures buyer tax details).
- **e-Tax Invoice / e-Receipt** (Easy E-Receipt) — the Revenue Department's electronic-submission layer; a separate integration entirely.

_Also_: ใบเสร็จ, ใบเสร็จรับเงิน, บิล
_Avoid_: Tax invoice / ใบกำกับภาษี (a non-VAT seller must not issue one — different document, deferred), Invoice (pre-payment billing doc — a Receipt is post-payment), ABB (deferred VAT variant)

**POS Return**:
A face-to-face refund/return at the counter — modelled as a **linked negative-line POS Order**, *not* a Return entity (see ADR 0009). A POS sale already *is* an Order; a POS return is the same shape with the sign flipped, carrying a `ref_order_id` to the original sale (bound to its `receipt_no` — returns must reference the original sale, the standard fraud/inventory control). Mechanics, all sign-inverted from a sale:
- **Stock**: each negative Order Line fires a `RECEIVE` (the inverse of the sale's `SHIP`); the Cashier's condition check routes good stock to On-Hand and damaged stock to Damaged (a `DAMAGE` instead).
- **Money**: a negative Payment Line refunds the buyer; a `cash` refund is cash out of the drawer and feeds the open Shift's `expected_cash` (the "− cash refunds" term).
- **Approval**: refunds are Admin-gated (a Cashier needs Admin approval — see Role).
- **Exchange** = a negative Order (the return) **plus** a positive Order (the new sale), settling the net difference — the standard way to compute an exchange's price delta.

There is **no** Inbound Scan, Return Sub-Status, or platform-closure lifecycle here — those exist only for the marketplace gap the POS counter doesn't have.

*MVP scope:* return must reference an original POS Order; **no-receipt returns and store credit are deferred.*
_Also_: คืนเงินหน้าร้าน, รีฟันด์ POS, คืนของหน้าร้าน
_Avoid_: Return (that's the marketplace/social entity with a lifecycle — a POS Return is a negative Order), Void (cancelling a sale before it closes is not the same as refunding a completed one), Store credit (deferred)

**Manual Discount**:
An ad-hoc price reduction a Cashier applies **at the counter** during a POS sale (haggling, a regular-customer courtesy) — deliberately a **different concept from a Promotion / Deal Price**, which is a scheduled, per-Listing price set up in advance for a Shop (the two are not one reused mechanism; see ADR 0010). A Manual Discount can be applied **per Order Line or to the whole cart**, as either a **percentage or a Baht amount**, and is recorded on the Order/Order Line so reporting and profit reflect the margin given away. When a Cashier applies one it is **Admin-gated** (discount is one of the three most-abused POS actions — see Role). In MVP a POS sale prices from **List Price + Manual Discount**; active Promotions are **not** auto-applied to POS (linking Promotions into POS is deferred).
_Also_: ส่วนลดสด, ส่วนลดหน้าร้าน, ลดราคา
_Avoid_: Promotion / Deal Price (scheduled, per-Listing — a Manual Discount is ad-hoc at the counter), Voucher (buyer-side platform code, not a counter discount)

**Parked Sale**:
A POS Order **held before payment** so the Cashier can serve the next customer (the buyer steps aside to grab more items) and resume it later. It sits in the pre-payment `รอชำระ` state — POS normally goes `รอชำระ → สำเร็จ` in one step, and a Parked Sale simply pauses at `รอชำระ`. Because POS only moves stock at `สำเร็จ` (immediate deduction, no reservation), **a Parked Sale touches no stock and no money until it is resumed and closed** — so any number of bills can be parked on a Register at once with zero accounting effect. Belongs to the open Shift / Register it was started on.
_Also_: พักบิล, บิลพัก, ค้างบิล
_Avoid_: Draft order (implies an editable saved order across channels — Parked Sale is the POS-counter hold specifically), Reserved (parking reserves no stock)
