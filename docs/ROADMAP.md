# All-Ecom Build Roadmap

ลำดับการ build แบบ greenfield ออกแบบให้ทำ **1 → N ไปข้างหน้าโดยไม่ต้องวนกลับมาแก้เฟสก่อนหน้า**.

## หลักการเรียงลำดับ (no-rework)

1. **Dependency-first** — สร้างสิ่งที่ทุกอย่างพึ่งพาก่อน (Catalog, Stock, Order kernel) ให้เสร็จก่อน channel/feature ใด ๆ.
2. **Kernel สร้างให้ครบตามที่ grill แล้ว** — เฟส 1–2 ต้อง implement semantics สุดท้ายเลย (immutable ledger, order-aware SHIP, unified Order, Cost Price history, multi-user + view-cost gate). ไม่ทำเวอร์ชัน "ง่าย ๆ ก่อน" ที่ต้องรื้อทีหลัง.
3. **เฟสหลังมีแต่ ADD** — slice ใหม่ (POS, marketplace, accounting…) ต่อยอด kernel ด้วยการ *เพิ่ม* entity/field/consumer ใหม่ ไม่ *แก้* core.
4. **เงื่อนไขเดียวที่อนุญาตให้ย้อนกลับไปแก้เฟสก่อน**: เจอว่าขัด industry standard จริง หรือเจ้าของเปลี่ยนการตัดสินใจโดยตั้งใจ. นอกนั้นห้าม.

อ้างอิงโมเดล: `CONTEXT.md` (glossary) + `docs/adr/0001–0015`.

---

## Phase 0 — Cross-cutting foundations (decide-once)

สิ่งที่ถ้าเลือกผิด → รื้อทั้งระบบ. ล็อกก่อนเขียนบรรทัดแรก.

| เรื่อง | การตัดสิน (ล็อก) |
|---|---|
| **Tech stack** | 🔒 **LOCKED: Laravel 11 + Filament + Livewire + Alpine + PostgreSQL**, deploy via Forge/Ploi บน Hetzner Cloud Singapore. เลือกเพราะ convention สูง 2 ชั้น (Laravel + Filament Resource pattern) → AI สร้างโครงมั่วไม่ได้. ดู `CONVENTIONS.md`. |
| **Guardrails** | Pint (format) · Larastan/PHPStan **level max** · Pest (test) · **Actions pattern** (1 business action = 1 class) · Form Request (validation) · Policy (role gate) · Job (bulk). บังคับผ่าน CI ก่อน merge. |
| **Money** | จำนวนเต็ม **สตางค์ (integer)** ทั้งระบบ — ห้าม float. THB only (ตามมติ THB-only). |
| **Time** | เก็บ UTC, แสดง `Asia/Bangkok`. ทุก milestone/timestamp เป็น timezone-aware. |
| **Ledger pattern** | append-only / immutable เป็น primitive กลาง (Stock Movement, Accounting cycle, Claim Timeline, Paid-in/out ใช้ร่วม). แก้ = append record ใหม่ ไม่ update/delete. |
| **IDs** | internal numeric/uuid ไม่โชว์ user; ทุก entity มี `created_at/updated_at` + `created_by` (User). |
| **Tenancy** | **multi-tenant row-level** (ADR 0011): สร้าง **Tenant table** + `tenant_id` ทุก domain table (FK target ของ Phase 1) + **app global scope** (stancl/tenancy `BelongsToTenant`) + **Postgres RLS** (app ต่อ DB ด้วย non-owner role) กัน 2 ชั้น. composite index นำด้วย `tenant_id` เสมอ; denormalized balances + rollups เป็น per-Tenant. เลื่อนแค่ signup/onboarding/billing — เส้นแบ่งข้อมูลมีตั้งแต่วันแรก. cross-tenant isolation test เป็น must. |
| **Audit** | การกระทำที่ admin-gated (void/refund/discount) ต้อง log ใครอนุมัติ. |
| **Queue + worker** | ตั้ง queue (DB driver ก่อน → Redis เมื่อโต) + persistent worker (Supervisor/systemd, Forge/Ploi ตั้งให้) + `import_job` status table. งาน bulk/async ทั้งระบบพึ่งอันนี้. |
| **Bulk pipeline** | เขียน **import pipeline กลางตัวเดียว** (upload → store → queued job → streaming parse → chunked upsert → fail-loud report + progress) reuse ทุกที่: Stock Adjustment (1), batch List Price, marketplace import (4), accounting import (6). |

**Exit:** repo skeleton + DB + money/time primitive + auth scaffolding + queue/worker + bulk-import pipeline กลาง พร้อมใช้.

---

## Phase 1 — Catalog + Stock kernel

ฐานที่ *ทุกอย่าง* อ่าน. สร้างให้ถูก semantics ตั้งแต่แรก.

**Build**
- **Product / Variant** — Master SKU, **List Price** (บน Variant, เท่ากันทุก platform), **Buffer** (per Variant×Location), barcode field.
- **Location (ADR 0013)** — entity ต่อ Tenant, auto 1 default. **stock เป็นต่อ `(Variant, Location)`**. Shop มี fulfilment Location. ทำตั้งแต่แรก — retrofit แพงเท่า tenant_id.
- **Bundle/Kit (ADR 0014)** — Variant ที่มี BOM (component Variant+qty), virtual (ไม่มี On-Hand เอง). Available = min(floor(component/qty)). order line ที่เป็น bundle → **expand เป็น component movements** ตอน reserve/ship (atomic) ที่ fulfilment Location. COGS=Σ component cost. ทำตั้งแต่แรกเพราะกระทบ order→stock.
- **Cost Price พร้อม change history** (`valid_from`) — accounting (เฟส 6) ต้องใช้ต้นทุน ณ วันขาย. ถ้าเก็บค่าเดียวต้องรื้อ.
- **Stock Movement ledger** — **9 actions** (`RECEIVE/SHIP/RESERVE/RELEASE/DAMAGE/RESTORE/RECOUNT/TRANSFER_OUT/TRANSFER_IN`), append-only, **`location_id`** ทุกแถว, `ref_type+ref_id`.
  - **SHIP เป็น order-aware ตั้งแต่แรก**: On-Hand− เสมอ, Reserved− เท่าที่ order จองจริง (marketplace/social=เต็ม, POS=0). → POS (เฟส 3) เสียบได้เลยไม่ต้องแก้.
- **Derived balances per `(Variant, Location)`** — On-Hand / Reserved / **Available = On-Hand − Reserved − Buffer (อนุญาตติดลบ)** / Damaged. (bundle Available = derived จาก components)
- **Stock Adjustment** — Excel in/out (recount/receive/damage/restore) + **inter-Location Transfer** (`TRANSFER_OUT`+`TRANSFER_IN` pair).

**Exit:** สร้างสินค้า, รับเข้า, ปรับสต็อก, query On-Hand/Available ได้ครบ. Available ติดลบได้.

### ⚠️ Scaling design constraints (บังคับ — ฝังตั้งแต่ Phase 1, ห้ามทำทีหลัง)

รองรับ **100k SKU + 10k order/วัน (~15–20M movements/ปี) บน box เดียว** ขึ้นกับ 2 กฎนี้ ไม่ใช่ขนาดเครื่อง:

1. **Denormalized current quantities** — เก็บ On-Hand / Reserved ต่อ **`(Variant, Location)`** เป็น **column ที่อัปเดตใน transaction เดียวกับการ append Stock Movement**. การอ่านสต็อก = O(1) (อ่าน column) **ห้าม `SUM()` ทั้ง ledger ตอน runtime**. ledger ยังเก็บครบเพื่อ audit/ตรวจย้อน (glossary: "derived **or denormalized** against the ledger"). bundle Available = derived จาก components (compute on read, cache optional).
2. **งาน bulk ทุกชนิดผ่าน queue + streaming + chunked upsert** — import/export/recalc ห้ามอยู่ใน web request. streaming = RAM คงที่ไม่ว่าไฟล์กี่แถว; chunked upsert (500–1000/chunk) = DB write เป็น batch.

**No-rework notes:** ledger immutable + SHIP order-aware + Available-can-go-negative + Cost history + 2 กฎ scaling ข้างบน = absorb POS, marketplace oversell, accounting, **และการโตถึงหลักแสน SKU / หมื่น order ต่อวัน** ล่วงหน้าหมดแล้ว. การ scale เกิน box เดียว (แยก DB box / read-replica / partition รายเดือน / Redis cache สต็อก hot) เป็น **additive ล้วน ไม่แตะ domain code**.

---

## Phase 2 — Identity + Shop + Order kernel

กระดูกสันหลังที่ทั้ง POS และ marketplace เสียบเข้า.

**Build**
- **Tenant resolver/context** (ADR 0011) — Tenant *table* + `tenant_id` + global-scope/RLS mechanism เกิดตั้งแต่ **Phase 0** แล้ว (เป็น FK target ของ Phase 1). Phase นี้ทำ **การ resolve tenant ตอน login** (เซ็ต current tenant ให้ global scope + RLS session var ใช้) + ผูกกับ User. (signup/onboarding/billing เลื่อน)
- **User / Role / Permission — custom RBAC (ADR 0012)** — spatie/laravel-permission + Filament Shield: Permission catalogue (system-defined, granular `area.action` แยก view/edit) + custom Role ต่อ Tenant (ติ๊ก permission) + seed Admin/Cashier default. `cost.view` = gate ต้นทุน. ทุก gated action เช็ค named permission ผ่าน Policy. ทำ RBAC ตั้งแต่ตอนนี้ ไม่ retrofit auth. (Role per-Tenant via spatie teams = tenant_id.)
- **Shop** (3 `platform_type`) + **Shop Settings** (fields เป็นเรื่อง marketplace money-flow; pos ไม่ใช้).
- **Order / Order Line / Order Status** (unified, ADR 0002) — discriminator ตาม platform_type, canonical 8 statuses + fail-loud mapping hook, **Order Milestone Dates** (nullable timestamps, upsert no-null-overwrite, ADR 0004).
- **Stock hooks** — wire RESERVE/RELEASE/SHIP จาก Order lifecycle เข้า ledger เฟส 1 (compensating movement สำหรับ pre-pack edit).

**Exit:** สร้าง Order ตรง (manual) → ยิง movement ถูก, query Order + สถานะ + timestamp ได้.

**No-rework notes:** discriminator + milestone + nullable + editable-rule = รองรับทั้ง POS (instant) และ marketplace (full lifecycle) ตั้งแต่ออกแบบ.

---

## Phase 3 — POS slice  ← shippable product ตัวแรก

ช่องขายที่ไม่พึ่ง external เลย → ได้ของใช้งานจริงเร็วสุด + validate kernel.

**Build** (`## POS` ใน CONTEXT.md ทั้งหมด)
- **Register** (auto-provision 1 ต่อ pos Shop) + **Shift** (open/close, opening_float, blind close, expected_cash, over_short, Paid-in/out).
- **Payment** — Payment Lines, 4 tenders, split tender, change, **manual-confirm**, cash → expected_cash.
- **Checkout** — barcode/Master SKU → Variant, **List Price + Manual Discount** (line/cart, %/฿, admin-gated), ปิดบิล → `สำเร็จ` → SHIP (immediate deduction).
- **Receipt** — render จาก Order+Payment, `receipt_no` รันต่อ pos Shop.
- **Parked Sale** — hold ที่ `รอชำระ`, ไม่แตะ stock/เงิน.
- **POS Return** (ADR 0009) — negative-line Order link บิลเดิม, RECEIVE, refund→drawer, admin-gated, exchange = neg+pos order.
- **over/short → Cash Over/Short** line (เตรียม P&L hook).

**Exit:** เปิดกะ → ขาย (split tender, ส่วนลด, พักบิล) → ออกใบเสร็จ → คืนของ → ปิดกะ blind → over/short. ครบ loop หน้าร้าน.

---

## Phase 4 — Marketplace import slice

ต่อ Order kernel ด้วย channel ภายนอก. ใหญ่สุด เพราะ per-platform.

**Build**
- **Listing / Platform SKU** — projection layer (marketplace เท่านั้น). Build a per-Shop **`(Shop, Platform SKU) → Variant` resolution map** (function, **many-to-one OK, ไม่ต้อง one-to-one**): SKU ซ้ำหลาย listing / หลาย SKU → Variant เดียว = รองรับ; SKU เดียวชี้ 2 Variant = **fail-loud conflict** ให้ seller resolve. Master SKU unique ต่อธุรกิจ. stock export เขียน Available ทุก Platform SKU ของ Variant นั้น. **Scope = lean/opportunistic (B):** เก็บ mapping + Deal Price + ฟิลด์ที่ import มีให้ (category/รูป URL) read-only — **ไม่ใช่ content management/PIM** (OMS category, ไม่มี API). additive ไป full content ทีหลังได้.
- **Order importers** (Shopee/Lazada/TikTok) — Excel parse, **status mapping fail-loud** (ADR 0005), upsert/dedup `(platform,shop,order_id)`, Order Line normalize→aggregate, milestone timestamps.
- **Reserved reconcile** — importer diff Order Lines → RESERVE/RELEASE.
- **Cancellation Reason** (cancelled_by/category/source, fail-loud).
- **Oversell alert** — list candidates, import-driven resolution.
- **Stock export Excel** — Available (clamp negative→0, หัก Buffer) ต่อ platform discount/stock file.

**Exit:** import ออเดอร์ 3 platform → stock/สถานะถูก, export stock กลับได้, oversell เตือน.

---

## Phase 5 — Returns + Stock Return (marketplace)

**Build:** Return (header+lines, ADR 0006), `return_type`, Return Sub-Status, **Inbound Scan**, Refund Status rollup, Stock Return (RECEIVE หลัง scan), dangling/stale detection, return import + dedup `platform_return_id`.

**Exit:** import return → Sub-Status → Inbound Scan → stock กลับ; refund_only ไม่แตะ stock.

---

## Phase 6 — Accounting + Reconciliation + P&L

**Build:** Accounting Entry (**cycle-aware import**, ADR 0007), Fee Category (8), Actual Net, Expected Net, Platform Fee Profile, Reconciliation Status, Hold Period/Settlement/Expected Payout/Mismatch (marketplace), **POS direct P&L (Payment − COGS, no fee)**, **Cash Over/Short**, Expense, **combined P&L รวมทุก channel**.
- **Report ที่ระดับล้านแถว ใช้ rollup table / materialized view (สรุปรายวัน)** ไม่ scan ดิบ runtime — สอดคล้องกฎ scaling Phase 1.

**Exit:** import บัญชี → Actual Net → reconcile → overdue เตือน; P&L รวม marketplace+POS ถูก.

---

## Phase 7 — Promotions + Margin

**Build:** Promotion / Promotion Line (base/campaign, 1 active line/T), Deal Price/Effective Price, export discount field ต่อ platform, Margin Calculator, expiry reminder. (POS ยังไม่ดึง Promotion — deferred.)

**Exit:** ตั้งโปร → Effective Price → export → margin calc แนะนำราคาได้.

---

## Phase 8 — Claims

**Build:** Claim (`return_fee`/`shipping_overcharge`), 6-status lifecycle, Evidence Checklist, Claim Timeline. พึ่ง Returns (5) + Accounting (6).

**Exit:** auto-flag claim จาก seller-fault return / shipping overcharge → ติดตามจนปิด.

---

## Dependency chain (สรุป)

```
0 (decide-once)
└─ 1 Catalog+Stock ──┬─ 2 Identity+Shop+Order ──┬─ 3 POS ✅ shippable
                     │                          ├─ 4 Marketplace import
                     │                          │    └─ 5 Returns
                     │                          │         └─ 8 Claims ─┐
                     │                          └─ 6 Accounting ───────┘
                     └────────────────────────────── 7 Promotions
```

**MVP ที่ขายได้จริงเร็วสุด = 0→1→2→3** (หน้าร้าน end-to-end). marketplace/บัญชี/โปร/เคลม ต่อยอดหลังจากนั้นโดยไม่แตะ 1–3.
