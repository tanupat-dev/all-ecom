# Conventions — all-ecom

**อ่านไฟล์นี้ก่อนเขียนโค้ดทุกครั้ง.** เป้าหมาย: convention เดียว ทั้งโปรเจกต์ โครงสร้างเหมือนกันทุกฟีเจอร์ — ห้ามประดิษฐ์ pattern ใหม่เอง. ถ้าจะเบี่ยงจากนี่ ต้องมีเหตุผลเขียนไว้ใน ADR.

แหล่งความจริง: **`CONTEXT.md`** (โดเมน/คำศัพท์) · **`docs/adr/`** (การตัดสินใจ 0001–0015) · **`docs/ROADMAP.md`** (ลำดับ build).

---

## Stack (locked)

- **Laravel 11** + **Filament** (back-office) + **Livewire + Alpine** (POS reactive) + **PostgreSQL**.
- **Dev runtime = WSL2 Ubuntu native** (project at `~/projects/all-ecom`, edited via VS Code Remote-WSL) — chosen over Herd (Windows-native, parity drift) and Sail/Docker (RAM-heavy) for true Linux parity at low RAM. **CI = GitHub Actions `ubuntu-latest`** (the parity net). Run Claude Code dev sessions from the WSL path so tools are native Linux. (Issue #1.)
- Deploy: **Forge/Ploi → Hetzner Cloud Singapore**. Queue: database → Redis เมื่อโต. Worker: Supervisor.
- ห้ามเพิ่ม dependency ใหญ่/เปลี่ยน stack โดยไม่มี ADR.

## Quality gates (บังคับ ผ่าน CI ก่อน merge)

- **Pint** — format (ห้าม custom style).
- **Larastan/PHPStan level max** — ไม่มี error.
- **Pest** — ทุก Action/flow มี test; **cross-tenant isolation test เป็น must**.

## Verify / hosting (ดู skill)

- **Verify a change** → skill `all-ecom-verify`: เลือก layer ถูกสุดที่พิสูจน์ได้ — in-process Pest (Unit→Feature→`Livewire::test()`) ก่อน, browser (Pest v4/Playwright) เฉพาะ POS Alpine cart. ส่วนใหญ่**ไม่ต้อง host server**.
- **ต้อง host server จริง** (visual/browser) → skill `all-ecom-local-server-hosting`: run_in_background → curl readiness → **stop + verify port&tree** → no orphan. คนที่ start คือคนที่ต้อง stop.

## โครงสร้าง / pattern (ทางเดียวเท่านั้น)

| ต้องการทำ | ใช้ pattern นี้ | ที่อยู่ |
|---|---|---|
| Business logic (1 การกระทำ) | **Action class** (1 action = 1 class, method `handle()`) | `app/Actions/...` |
| Validation | **Form Request** | `app/Http/Requests/...` |
| Auth/permission gate | **Policy** เช็ค **named Permission** (spatie/laravel-permission + Filament Shield gen per-Resource) — ห้าม `if role=='admin'` | `app/Policies/...` |
| Back-office CRUD/หน้าจอ | **Filament Resource** (Schema/Table/Action ตามแม่พิมพ์) | `app/Filament/...` |
| POS / หน้า reactive | **Livewire component** (+ Alpine สำหรับ cart client-side) | `app/Livewire/...` |
| งาน bulk/async (import/export/recalc) | **Job** (queued, chunked) ผ่าน central import pipeline | `app/Jobs/...` |
| Query ซับซ้อน reuse | Eloquent scope / Builder method | บน Model |

- **ห้าม**ใส่ business logic ใน Controller, Model, หรือ Livewire component — ย้ายไป **Action**.
- Controller บาง (เรียก Action แล้วคืน response เท่านั้น). Model = relationships + casts + scopes, ไม่มี logic หนัก.

## กฎโดเมนที่ห้ามพลาด (จาก ADR)

1. **Money = integer สตางค์** ทุกที่. ห้าม float. cast เป็น value object/integer. THB only.
2. **Multi-tenancy (ADR 0011)** — ทุก domain model `use BelongsToTenant` (global scope auto) + **Postgres RLS** เปิดทุกตาราง; app ต่อ DB ด้วย **non-owner role**. ทุก composite index/unique นำด้วย `tenant_id`. "unique per business" = unique `(tenant_id, ...)`.
3. **Stock = immutable ledger (ADR 0003)** — แก้สต็อก = **append Stock Movement** เท่านั้น, ห้าม update/delete. **stock เป็นต่อ `(Variant, Location)`** (ADR 0013): Movement มี `location_id`; current quantity column denormalized ต่อ (variant, location) อัปเดตใน transaction เดียวกับ movement — ห้าม `SUM()` ledger ตอน runtime. Transfer ข้าม Location = `TRANSFER_OUT`+`TRANSFER_IN` คู่.
3b. **Bundle/Kit (ADR 0014)** — virtual, ไม่มี On-Hand เอง; Available = min(floor(component/qty)). order line ที่เป็น bundle → **expand เป็น component RESERVE/SHIP/RELEASE atomic** ที่ fulfilment Location; ห้าม move "bundle stock". COGS=Σ component cost ณ วันขาย.
4. **`SHIP` order-aware** — On-Hand− เสมอ, Reserved− เท่าที่ order จองจริง (POS=0). **Available ติดลบได้** (oversell), clamp 0 ตอน export เท่านั้น.
5. **Import = fail-loud (ADR 0005)** — ค่าที่ map ไม่ได้ (status/reason/SKU/fee) **ห้าม default มั่ว** → surface error + hold record.
6. **SKU resolution = function** — `(tenant_id, Shop, Platform SKU) → Variant` หนึ่งตัว; many-to-one ได้; SKU เดียว→2 Variant = fail-loud. Master SKU/barcode unique ต่อ tenant.
7. **Accounting (ADR 0007)** cycle-aware; **POS ไม่มี Accounting Entry** (P&L ตรง = Payment − COGS).
8. **RBAC (ADR 0012)** — custom Role ต่อ Tenant = ชุดของ **Permission** (catalogue ระบบกำหนด, granular `area.action` แยก view/edit). ทุก gated action เช็ค **named Permission ผ่าน Policy** (ห้าม hardcode role); cost.view = gate ต้นทุน; void/refund/discount = permission + log ผู้อนุมัติ. Role per-Tenant (spatie teams), **create/edit/delete role ได้** (ลบ role ที่ใช้อยู่ = strip จาก user ก่อน + warn), seed Admin/Cashier default, ห้าม lock-out user.manage/role.manage คนสุดท้าย.
9. **Cost Price มี history** (`valid_from`) — profit ใช้ต้นทุน ณ วันขาย.

## DB / migration

- ทุกตาราง: `tenant_id` (FK, index นำ), `created_at/updated_at`, `created_by`.
- เปิด **RLS policy** ในตอน migration ของทุก domain table.
- index ตาม lookup จริง: `(tenant_id, master_sku)`, `(tenant_id, platform, shop, platform_sku)`, `(tenant_id, variant_id)` บน movements, ฯลฯ.
- ledger/movements + orders: เตรียม **partition รายเดือน** ได้ (ทำตอนโต, ไม่ใช่ตอนนี้).

## Naming / ภาษา

- โค้ด/ตาราง/ฟิลด์ = อังกฤษ ตามคำใน `CONTEXT.md` (Tenant, Shop, Variant, Stock Movement, Shift, …).
- สถานะ/ค่าที่ผู้ใช้เห็น (Order Status `รอชำระ/สำเร็จ/…`) เก็บเป็น canonical ตาม CONTEXT.md.
- UI = ไทย.

## Skills (ใช้ตามจังหวะงาน)

- **backlog/checklist "เหลืออะไร" = GitHub Issues** + triage labels → `all-ecom-triage` (`ready-for-agent`=AFK / `ready-for-human`=HITL). แตก plan → issues ด้วย `all-ecom-to-issues`
- **จบ session / "handoff" / "รอบหน้าทำไร" → `all-ecom-handoff`** (เอกสาร ephemeral ลง OS temp, อ้าง artifact + issue# + commit, ไม่ commit เข้า repo)
- ก่อนเขียนงานไม่ trivial → `all-ecom-engineering-process` (plan-first + vertical slice + AFK/HITL)
- เขียนแต่ละ slice → `all-ecom-tdd` (red→green→refactor, behaviour ผ่าน public interface)
- ก่อน lock design ที่ rollback แพง (money/stock/ordering) → `all-ecom-standard-first`
- ก่อนออกแบบฟีเจอร์ money/stock → `all-ecom-business-rules-check`
- ก่อนสร้าง construct ใหม่ → `all-ecom-search-before-write`
- ก่อน commit โค้ด sensitive (tenancy/RBAC/payment/PII/SQL/secret) → `all-ecom-security-check`
- verify การเปลี่ยนแปลง → `all-ecom-verify` · ต้อง host server → `all-ecom-local-server-hosting`
- **ทุก commit ที่เปลี่ยน behaviour/ชื่อ/term/decision → `all-ecom-consistency-sweep`** (กวาด stale doc/comment/test/dead-code อัปเดตใน commit เดียว)

## เมื่อไม่แน่ใจ

ถาม/เช็ค `CONTEXT.md` + ADR ก่อน. ถ้าต้องตัดสินใจใหม่ที่กระทบโครงสร้าง → เขียน ADR ใหม่ (supersede ไม่ใช่แก้ทับ) ไม่เงียบ ๆ เบี่ยง convention.
