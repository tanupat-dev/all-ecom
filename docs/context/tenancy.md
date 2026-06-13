# Tenancy & Access

Who and where: the Tenant isolation boundary, the Platforms and Shops a Tenant sells through, and the Users / Roles / Permissions that act within it. The shared kernel — every domain row carries `tenant_id` (ADR 0011).

## Language

**Tenant**:
The top-level isolation boundary — one signed-up **seller business**. all-ecom serves many Tenants on one deployment, and everything else belongs to exactly one Tenant: its **Users** (with their own Roles), its **Shops** across platforms, its **Locations**, and its entire catalog, stock, orders, accounting, and promotions. No data is ever visible across Tenants. Isolation is **row-level**: every domain row carries `tenant_id`, enforced by *both* an application global scope *and* PostgreSQL Row-Level Security (defense in depth — a forgotten filter still can't leak one seller's data to another). "Unique per business" constraints (Master SKU, barcode) and the Roles & Permissions (see ADR 0012) are all scoped **per Tenant**. (See ADR 0011.)
_Also_: ธุรกิจ, ร้าน (the whole business, not one platform Shop), Seller Account, Organization
_Avoid_: Shop (one platform account *under* a Tenant — a Tenant has many Shops), User (a person within a Tenant), Business unit

**Platform**:
A sales channel. Each Platform has a `platform_type` that determines its sync behavior and capabilities:
- **`marketplace`** — Shopee, Lazada, TikTok. Excel/CSV import for Orders, Excel export for stock/promo updates. Has Tracking.
- **`social`** — LINE, IG, Facebook. No Platform export — sellers chat with buyers and **manually enter** Orders into our system. Has Tracking (normal courier shipping).
- **`pos`** — Physical storefront. Created via our checkout UI in real-time. **No Tracking**, instant lifecycle (รอชำระ → สำเร็จ in one step).

All three types share the same Order / Order Line / Stock model — they differ in **how Orders enter the system** and **which lifecycle states apply**.
_Also_: ช่องทาง
_Avoid_: Marketplace (only one of three types), site, channel (overloaded)

**Shop**:
A specific seller account on a Platform, **belonging to one Tenant**. One Platform can host multiple Shops (e.g., `tiktok1`, `tiktok2`) under the same Tenant, each potentially carrying a different (possibly overlapping) set of Products. Each Shop is assigned a **fulfilment Location** — the place its stock is drawn from / exported for (see Location); many Shops may share one Location. A Shop is the seller's **user-named** account (`tiktok1`, `tiktok2`, … — renameable) and the axis of the per-Shop **upload hub** (its imports and import history live under it, grouped by Platform in the back-office nav; ADR 0023). A Shop that has accumulated history (Orders, Listings, accounting) is **archived, never hard-deleted** — archiving hides it and blocks new imports/sales while preserving every record; hard delete is permitted only for a Shop with **no dependent rows** (a fresh mistake). (See ADR 0023.)
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

