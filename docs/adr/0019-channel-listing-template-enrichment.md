# Channel listing-template enrichment: fill the Platform's own bulk-upload file from a channel-agnostic master, never become a PIM

all-ecom adds a **bounded** capability to help sellers list products onto Shopee / Lazada / TikTok in bulk **without a Platform API**: the seller authors a **channel-agnostic master** in our catalogue (name, English name, description, brand, package weight/dimensions, and hosted **Product Images**), downloads the **Channel Upload Template** from each Platform (category — and for TikTok, brand — already chosen, so the category-specific columns and machine tokens are baked in), brings it into all-ecom, and the system **fills only the columns it authoritatively owns**, emitting a per-Platform file the seller uploads. We deliberately **do not** model Platform category taxonomies, **do not** store per-channel content as master, and **do not** sync two-way. This is the additive step that CONTEXT.md's *Listing* term previously deferred ("out of scope and additive later") — kept bounded.

## Why bounded-fill, not generate, and not a PIM

- **Generating the template from scratch is impossible without the Platform's category service.** Each downloaded template embeds machine tokens the Platform mints when the seller picks a category/brand — TikTok a `create_product` + `category_v2` token, Shopee an md5 + category-id, Lazada a per-category `_hide` sheet of `catProperty.*` validation. We cannot fabricate these. The seller's downloaded file is the only viable transport — we **fill**, never **generate**.
- **A full PIM is un-maintainable here and rots immediately.** Owning each Platform's category tree + per-category attribute schema + the mandatory/optional/forbidden matrix is a product in itself, and with no API the master can never sync back — it is stale the day it is written. Rejected.
- **AI is not the missing piece.** A complete deterministic fill covers every *universal* required column (name, description, brand, price, stock, weight, dimensions, variant/SKU, image URL); the only residue is category-specific attributes, which AI could *suggest* but must not invent (ADR 0005 ethos). So AI enrichment is demoted to an optional, review-gated accelerator — out of the first build.
- **Images are solvable without an API.** All three Platforms accept an **external image URL** in bulk upload (Lazada requires one; Shopee and TikTok accept one), so hosting a normalised (1:1) **Product Image** on object storage (Cloudflare R2 — zero egress when the Platform fetches it) fills the image columns; TikTok additionally drops image-less rows to its own *Draft* as a fallback.
- **Brand is fillable.** Lazada `brand` is free text; Shopee/TikTok accept "No Brand". Not a blocker.
- **It respects the channel-model boundary (ADR 0010).** The shared core grows (a richer channel-agnostic master), and the only channel-specific thing added is the per-Platform *file format* of the fill — exactly "extend the shared core; split only where behaviour diverges", not a per-channel content store.
- **The seller pain is real and otherwise unaddressed.** Small sellers cannot remember whether each Platform carries the full, identical catalogue; **Listing Coverage** (rebuilt by importing each Platform's existing-product export, not from memory) plus one-master-many-templates fill is the standard cross-listing answer, adapted to no-API.

## How it is bounded (the line we do not cross)

- **Fill only owned, channel-agnostic columns:** Master/Platform SKU, name (+ optional English), one shared description, brand text, price, stock, variant options, package weight/dimensions, Product Image URLs.
- **Leave to the seller / Platform:** category selection (baked in the download), category-specific attributes & compliance docs (อย./มอก./qualifications), delivery/COD settings, brand authorisation for trademark brands.
- **No Platform category taxonomy, no per-channel content master, no two-way sync, no auto-push** — the seller always uploads the file themselves (consistent with ADR 0001, Excel-only, no API).
- Column mapping anchors on each file's **machine-key row**, not its localised labels (robust to Thai-label changes).
- Money fills convert satang → the Platform's baht field (ADR 0015); fill conflicts (e.g. an unmatched SKU on existing-listing import) are **fail-loud**, never silently merged (ADR 0005).

## Considered options

- **Full PIM** (model category taxonomies + per-channel content as master, generate templates). Rejected — un-maintainable without an API, the master rots immediately, and it violates the lean OMS positioning.
- **Generate the bulk file ourselves.** Rejected — the baked category machine-tokens cannot be fabricated.
- **AI-first listing generation** (vision → full listing). Rejected as the *primary* mechanism — not required (deterministic fill suffices), and it invents facts (specs/attributes) a listing must not carry unverified. Kept only as a deferred, review-gated assist.
- **Stay out of scope** (status quo per the old *Listing* note). Rejected — the "which Platform is missing which SKU / list once" pain is real and now demonstrably solvable within the no-API boundary.

## Consequences

- **Catalogue gains channel-agnostic master fields** — Product: English name, description, brand; Variant: package weight + dimensions — and a new **Product Image** stored on R2 (normalised 1:1), reused on POS and in Listing Coverage.
- **New constructs:** a **Channel Upload Template** importer + per-Platform filler/exporter (through the central import pipeline), an **existing-listing ("All product") importer** that rebuilds **Listing Coverage**, a Coverage screen, and a **Listing Status** (`draft` → `listed`) on ListingVariant — `draft` on fill, `listed` on seller confirmation or on existing-listing import (ground truth). No Platform API verifies a listing, so the two states keep Coverage honest.
- **Round-trip everywhere the seller owns the data** — every Excel import the seller authors (catalogue, prices, coverage) has a paired export to edit and re-import (SKU-keyed); read-only Platform mirrors (orders/returns) export for reporting only.
- **Still not a PIM** — per-channel content authoring beyond the owned columns, two-way sync, and auto-push remain out of scope and would each need their own ADR (and, realistically, an API — ADR 0001).
- A new ROADMAP phase carries this work; it depends on Phase 4 (Listing / Platform SKU) and is independent of Phase 6 (Accounting).
- CONTEXT.md's *Listing* scope note is updated; new terms **Channel Upload Template, Listing Coverage, Listing Status, Product Image** are added.
