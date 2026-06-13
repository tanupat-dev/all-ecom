# Pricing & Promotions

How the price a buyer pays is set: Promotions and their authoritative Promotion Line (ADR 0021), and the List / Deal / Effective Price resolution.

## Language

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

