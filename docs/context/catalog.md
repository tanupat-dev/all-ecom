# Catalog

The product catalogue: Products, Variants, Bundles, their SKUs, the per-Shop Listings, and the channel-agnostic master that fills a Channel Upload Template (ADR 0019).

## Language

**Product**:
A model/รุ่น in the catalog — the parent record that groups Variants sharing the same name, images, description, and category. A Product on its own is not sellable; its Variants are. Roughly equivalent to a "product page" on a marketplace. The **channel-agnostic listing content** the seller authors once — name, optional English name, master description, brand text, and **Product Images** — lives here (shared across every Platform), and is what fills a **Channel Upload Template** (ADR 0019).
_Also_: สินค้า, รุ่น, Master Product
_Avoid_: Item, SKU (SKU is a Variant attribute), listing (a Listing is the Shop-side projection of a Product)

**Variant**:
The actual sellable unit — one specific combination of attributes (e.g., color + size) under a Product. Variant is the level at which Master SKU and List Price live; **Stock and Buffer are tracked per `(Variant, Location)`** (see Location). Package **weight and dimensions** (per-unit, used for shipping and to fill a Channel Upload Template; ADR 0019) also live on the Variant. A Product with no real options still has exactly one default Variant.
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

**Scope (bounded — an OMS with channel-listing *assist*, not a PIM; ADR 0019):** a Listing itself stores only the **SKU mapping + Deal Price** (+ any per-platform fields an import hands us, kept read-only). The catalogue's **channel-agnostic master** — name, English name, description, brand, package weight/dimensions, hosted **Product Images** (shared across Platforms, never per-channel) — is what feeds the **Channel Upload Template** fill: the system enriches a Platform's own downloaded bulk-upload file with the columns it owns and emits a per-Platform file the seller uploads. It is still deliberately **not** a content-management/PIM layer — we do **not** model Platform category taxonomies, do **not** store per-channel descriptions/images/attributes as master, and do **not** sync two-way (no Platform API). Authoring genuinely per-channel content beyond the owned columns stays out of scope.
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

**Channel Upload Template**:
The Platform's **own** bulk-listing spreadsheet — Shopee "Mass Upload", a Lazada bulk template, a TikTok bulk-listing file — that the seller **downloads from the Platform** with the category (and, for TikTok, brand) already chosen, so its category-specific columns and machine tokens are baked in by the Platform. all-ecom **never generates** this file (the tokens can't be fabricated without the Platform's category service); the seller brings it in and the system **fills only the columns it authoritatively owns** from the channel-agnostic master — Master/Platform SKU, name, description, price, stock, variant options, package weight/dimensions, brand text (or "No Brand"), and **Product Image** URLs — leaving category-specific attributes for the seller. Output is **one filled file per Platform template** (Lazada = one sheet per leaf category, ≤20). Column mapping anchors on each file's **machine-key row**, not its localised Thai labels. (See ADR 0019; bounded — not a PIM.)
_Also_: bulk upload template, mass-upload file, แบบฟอร์มลงสินค้า
_Avoid_: Listing (the per-Shop projection, not the file), generated template (we fill, never generate)

**Listing Coverage**:
The Variant × Shop matrix of **which Variants are listed on which Shops** — and, the point, **which are missing** — so a seller who can't remember whether each Platform carries the full, identical catalogue sees the gaps. A gap = a Variant with no ListingVariant on a given marketplace Shop. Populated both by filling a **Channel Upload Template** (which declares a Listing) and by importing a Platform's **existing-product export** ("All product" file) to reconstruct coverage **from reality, not memory**. (See ADR 0019.)
_Also_: coverage matrix, ความครบของการลงสินค้า
_Avoid_: Stock coverage (that's Available, a different concept)

**Listing Status**:
A two-value state on each **ListingVariant** marking how sure we are the listing is live: `draft` (the seller filled a Channel Upload Template for it but hasn't confirmed uploading to the Platform) → `listed` (the seller confirmed the upload, **or** the row came from importing the Platform's existing-product export = ground truth). Because there is no Platform API to verify, the two states keep **Listing Coverage** honest about intent vs confirmed reality. (See ADR 0019.)
_Also_: สถานะการลง
_Avoid_: Order Status (unrelated — that's the order lifecycle), published (we can't confirm publish without an API)

**Product Image**:
A product photo **stored by all-ecom itself** (object storage / Cloudflare R2), normalised to a square (1:1) so it passes every Platform's bulk-upload image rules. Its public URL is written into a Channel Upload Template's image columns — all three Platforms accept an **external image URL** in bulk upload (Lazada requires one; Shopee/TikTok accept one), so hosting the image is what lets the image columns be filled **without a Platform API**. **Channel-agnostic** — one set of images per Product/Variant, shared across Platforms — and reused on the POS screen and in Listing Coverage. (See ADR 0019.)
_Also_: รูปสินค้า
_Avoid_: per-channel image (images are shared master, not per-Listing), platform CDN image (the Platform re-hosts on its own CDN when it fetches our URL)

