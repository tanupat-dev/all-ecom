# Per-Shop fulfilment hub + the Dispatch Round print-batch, with a local Printed Flag — not the Platform status — as the "already-handled" authority

all-ecom is an OMS whose Orders arrive by **Excel import, not a Platform API** (ADR 0001). Sellers asked for the missing operational half: from the imported Orders, **pick, pack, and dispatch** them — print a **Pick List** (aggregated SKUs to grab) and a **Dispatch Manifest** (the per-Order control roster), organised around the seller's real Shops. The current Order screen is a bare read-only mirror list (no search, no filters, no detail, no documents). This ADR pins how that fulfilment surface works, and the few data/PII/lifecycle decisions it forces — each costly to reverse once Orders, documents, and seller habits depend on it.

## Decision

### 1. Per-Shop upload hub; the Order list is a table, the Order detail is cards

The back-office groups upload + import-history **per Shop** (the seller's user-named `tiktok1`/`tiktok2`/`shopee1`…), grouped visually under their Platform — **not** three fixed Platform pages. Every uploadable artifact (orders, settlement, stock export, template fill, all-product) resolves to a **Shop**, never a bare Platform; one Platform may host several Shops with different Locations and catalogues, so a Platform-level page is ambiguous (CONTEXT.md: Shop).

The **Order list is a data table** (rows), not a card grid — the cross-industry standard for high-volume order management, where users scan, compare, and bulk-act on thousands of rows; cards belong in the **single-Order detail view** (sectioned panels: header, customer, items, shipping, timeline). Primary actions (**Export Pick List / Manifest**) sit **top-right** of the toolbar; filters and status tabs sit **above** the table; **bulk actions** (create Round / export / print) appear contextually when rows are selected. The list gains what it lacks today: a **`platform_order_id` column**, **search** (order no. / Tracking / recipient), **filters** (status multi-select · Shop · platform · date range + presets), **status tabs with count badges**, and a **View page**. Orders stay **read-only** (ADR 0002) — this adds finding/viewing/printing, not editing.

### 2. The Dispatch Round is a cross-Shop print-batch; the Printed Flag is the dedup authority — never the Platform status

A **Dispatch Round** is the unit a seller picks/packs/prints in one pass (CONTEXT.md: Dispatch Round). It is **cross-Shop** (sellers ship several Shops in one trip) and is formed by **filtering the ready-to-fulfil pool, then confirming** — fully manual, never auto-scheduled. There is no real-time order stream to trigger a schedule (Orders arrive by bulk Excel import), and the industry leader for Excel-driven shipping (ShipStation) likewise batches by **manual select + saved filters**, not automation.

The **ready-to-fulfil pool** is:

> `phase ∈ { รอแพ็ค, แพ็คแล้ว }` **AND** `Printed Flag = false`

The hard part is **dedup across re-imports**, because the Platform Order Status mismatches physical reality in **both** directions:

- **Lag** — the seller handled the Order, but a fresh export still shows `รอแพ็ค` → a status-based pool pulls it back and **re-prints**.
- **Jump** — the seller clicked *Ready-to-ship* on the Platform first (to mint the AWB) → the next import lands the Order at `แพ็คแล้ว` while the goods are still on the shelf, **mixing printed and un-printed Orders at one status**.

Therefore **the Platform status is not the fulfilment signal.** The authority is a **local, sticky `Printed Flag`** (and `round_id`), set when a Round is created and **never cleared by re-import**. Re-import stays idempotent on `(shop_id, platform_order_id)` and keeps mirroring the Platform status, but **must not touch `round_id`/`Printed Flag`**, and **must not regress** a handled Order back into the pool. This makes the recommended workflow (*import → print from us → then Ready-to-ship on the Platform*) a **best practice, not a correctness requirement** — robustness comes from the flag, not from the seller's click order. The list shows an *"in Round #123 · printed"* vs *"not yet"* badge so the "pile at แพ็คแล้ว" is always disambiguated on screen.

Because the pool **spans `รอแพ็ค` and `แพ็คแล้ว`**, the *Ready-to-ship-first* workflow never loses Orders from picking.

### 3. Two documents, printed together per Round; plus filtered bulk-print of any status/date

A Round emits, **together**:

- **Pick List** — every ordered SKU **aggregated to a total quantity**, channel-agnostic (one line for a Variant sold on several Shops); internal picking doc.
- **Dispatch Manifest** — **one row per Order**, **seller-customisable columns** (saved template per Tenant), exported to Excel → print/PDF.

High volume is handled by cutting **several smaller Rounds per day**, not by a nested sub-batch level (rejected as over-engineering; ShipStation's batch is a single unit). Beyond the default ready-to-fulfil preset, the same screen offers **filtered bulk-print of any status(es) + date range** (e.g. re-print all `กำลังขนส่ง`+`ถึงปลายทาง` for a day) for reporting/re-prints — with a **reprint warning** when the selection includes already-printed Orders (the ShipStation/Shopify pattern).

### 4. Capture only the fulfilment fields the documents need — data-minimised PII

To fill the Manifest we capture, **read-only** from the Platform export, **recipient name · province · Buyer Message · shipping provider** (Tracking Number already exists). We **do not** store full street address or phone — the real address travels on the Platform's **AWB**; province is enough to sort/control, and storing less is the defensible PDPA posture (data-minimisation; CLAUDE.md: customer PII is sensitive).

- **Buyer Message** exists for **Shopee** (`หมายเลขคำสั่งซื้อ`→`หมายเหตุจากผู้ซื้อ`) and **TikTok** (`Buyer Message`); **Lazada has no buyer-to-seller order remark at all** (buyers use live chat — verified against the real export and the Lazada API, whose `Remarks`/`GiftMessage` are unused and, being API-only, off-limits under ADR 0001). So Lazada's Buyer Message is **left blank, fail-loud** (ADR 0005), and the seller's **Internal Note** is the manual stand-in.
- **shipping provider** is the courier (Flash/Thailand Post/…): parsed from Shopee `ตัวเลือกการจัดส่ง` (courier embedded in the tier string), TikTok `Shipping Provider Name`, Lazada `shippingProvider`. The handover-method column (`วิธีการจัดส่ง` = Drop-off/Pickup) is **not** captured — near-constant per Shop, no per-parcel value.

### 5. Shop archive, never destroy

A Shop anchors Orders, Listings, Returns, payments, accounting roll-ups, and promotions. **Archiving** (an `archived_at`) hides the Shop and blocks new imports/sales while preserving all history; **hard delete is allowed only for a Shop with zero dependent rows**. Destroying a Shop that has sold would orphan financial + append-only records — against the system's ethos and the marketplace norm (Shopify/seller-centers deactivate channels, never delete a sold one).

## Considered options

- **Per-Platform pages (3 fixed).** Rejected — every uploadable artifact targets a *Shop*; a Platform with `tiktok1`+`tiktok2` makes "which shop's settlement/orders?" ambiguous (CONTEXT.md: Shop).
- **Card grid for the Order list.** Rejected — cards are low-density; tables are the standard for scan/compare/bulk at thousands of rows. Cards kept for the Order *detail*.
- **Platform status as the dedup signal** (pool = `รอแพ็ค`, or mark `แพ็คแล้ว` on print). Rejected — the status lags *and* jumps vs physical reality, causing re-prints and a mixed `แพ็คแล้ว` pile; a local sticky `Printed Flag` is the only honest authority.
- **Auto-scheduled Rounds by cutoff time.** Rejected — no real-time order stream (Excel import); cutoffs (Shopee ~14:00, Lazada ~13:00 RTS) become **reminders**, not triggers. Manual cut + saved filters, like ShipStation.
- **Nested Round → sub-batch level.** Rejected as over-engineering — cut more, smaller Rounds instead.
- **Store full recipient address/phone.** Rejected — PDPA data-minimisation; the AWB already carries it.
- **Cross the no-API line to fetch Lazada `Remarks`.** Rejected — violates ADR 0001 and the field is empty in practice anyway; Internal Note covers the rare case.
- **Hard-delete Shops.** Rejected — orphans financial/append-only history; archive instead.

## Consequences

- **New constructs:** a **Dispatch Round** (cross-Shop, `round_id` on Order) producing a **Pick List** (SKU-aggregated) + a customisable **Dispatch Manifest**; a local sticky **Printed Flag**; per-Shop **upload hub** nav grouped by Platform; a full Order **table** (search/filters/status-tabs/bulk) + **View** page; **filtered bulk-print** with a reprint warning.
- **Schema additions:** Order gains `round_id`, `printed_at` (Printed Flag), `internal_note`, `recipient_name`, `province`, `shipping_provider`; Shop gains `archived_at`. A new `dispatch_rounds` table. All carry `tenant_id` + `BelongsToTenant` + RLS (ADR 0011).
- **Order importers extend** to map recipient name, province, Buyer Message (Shopee/TikTok), and parse the courier into `shipping_provider` — fail-loud on unmapped values (ADR 0005); **re-import must preserve** the local fulfilment fields and never un-print/regress a handled Order.
- **PII surface grows** (recipient name, province, buyer message) — covered by the tenant scope + RLS; gated behind the same `order.view` permission; full address/phone deliberately not stored.
- **CONTEXT.md** gains *Dispatch Round, Pick List, Dispatch Manifest, Printed Flag, Buyer Message, Internal Note*, and updates *Shop* (archive + per-Shop hub) and *Order* (local fulfilment fields + captured PII).
- **Still no Platform API, still no manual editing of marketplace Orders** — Orders remain mirrors; only the *local fulfilment overlay* (round/printed/internal note) is ours to own.
- A new ROADMAP phase carries this work; it depends on Phase 4 (Order import / Shop) and is independent of the accounting and promotions phases.
