# Expected shipping is computed from catalog weight/dimensions — max(actual, volumetric), tiered, summed across Order Lines

---
Status: accepted, but **demoted to the fallback path by ADR 0024**. The catalogue-derived expectation below is no longer the *primary* `shipping_overcharge` mechanism: each Platform discloses its own per-order actual shipping (TikTok billed-weight, Shopee subsidy-aware net, Lazada Shipping Fee statement), and ADR 0024 detects against those. The formula here remains the **last-resort fallback** when no disclosed-actual export exists, and `expected_shipping_rate` keeps the shape pinned below. The "uniform, all-platforms, `|shipping_seller_paid|` (net) vs catalogue" flag condition in §4 is **superseded** — it false-flags subsidised sellers and misses overcharges a platform discount/subsidy nets down.
---

Phase 8's `shipping_overcharge` **Claim** (CONTEXT.md: Claim) auto-flags when a courier billed the seller more than the seller fairly expected to pay. CONTEXT pins the *trigger* — "auto-flagged from Accounting Entry analysis when `shipping_seller_paid` exceeds the expected rate (`expected_shipping_rate` in Shop Settings)" — but leaves **how the expected rate is computed** and **what shape `expected_shipping_rate` takes** undefined. This ADR pins both, so the auto-flag is deterministic and `expected_shipping_rate` becomes a contract.

## Decision

Expected shipping for an Order is **computed from the catalogue** (the Variant's package weight + dimensions, ADR 0019) at Accounting-Entry-import time — never stored per Order — and compared against what the Platform actually deducted.

1. **Chargeable weight = `max(actual weight, volumetric weight)`.** This is the cross-platform standard: Shopee, Lazada, and TikTok (SEA) each bill the **greater** of actual and volumetric weight, with the **divisor 5000**. Our catalogue stores `package_weight_g` (grams) and `package_{length,width,height}_mm` (millimetres) on the Variant. The unit identity is clean:
   `volumetric_grams = (L_mm × W_mm × H_mm) / 5000`  — because `cm³ / 5000 = kg` is exactly `mm³ / 5000 = g`.

2. **Aggregate across all Order Lines** — an Order with more lines/quantity weighs more and ships dearer (the owner's rule). MVP model is **per-unit-additive**:
   `order_chargeable_g = Σ over lines of max(package_weight_g, volumetric_g) × qty`.
   This is monotonic in lines and quantity and uses both weight and dimensions. It is deliberately an **approximation**: we do not model the real packed parcel, which nests items, so true consolidated volumetric ≤ this additive sum. The additive sum therefore **over-estimates** volumetric, which biases the rule **against false positives** — correct for a *verify-prompt* (the Claim prompts the seller to check, it never auto-files or auto-deducts).

3. **`expected_shipping_rate` shape** (the previously-free `array` on Shop Settings, now contractual): an **ascending-ordered list of weight tiers**
   `[{ "up_to_g": int, "fee": int /* satang */ }, …]`.
   The expected fee is the `fee` of the **first tier whose `up_to_g ≥ order_chargeable_g`**. If the weight exceeds every tier, use the **highest tier's fee** (conservative — a heavy parcel won't be false-flagged). A **null/empty `expected_shipping_rate` means the Shop has configured no expectation → no `shipping_overcharge` flag** (fail-safe: we never guess a rate — ADR 0005 posture).

4. **Flag condition.** Let `paid = |shipping_seller_paid|` (the Accounting Entry line is signed negative — the seller pays; ADR 0020). Auto-flag an `eligible` `shipping_overcharge` Claim on the Order when
   `paid − expected_fee > TOLERANCE`.
   `TOLERANCE` is a documented constant — **฿5 (500 satang)** — absorbing courier rounding and small fuel/remote-area surcharges. Pinned in the Action; may later move to Shop Settings if sellers need to tune it. **One `shipping_overcharge` Claim per Order** (idempotent on re-import).

5. **Missing inputs are fail-safe, never guessed.** A Variant without weight/dimensions, or a Shop without `expected_shipping_rate`, yields **no expected fee → no flag** (we surface "no expected rate" rather than fabricate one — ADR 0005). A Bundle uses its own bundle-Variant package weight/dimensions if set (it ships as one parcel); falling back to components is a deferred refinement — MVP uses the Order Line's Variant fields.

6. Money is integer **satang** throughout (ADR 0015). All amounts compared in satang.

## Why

- **It is the industry standard.** All three target Platforms compute the chargeable weight as `max(actual, volumetric)` with divisor 5000 (SEA), then bill it against a tiered rate card; the courier re-measures after handover and recalculates — which is exactly where overcharges originate. Anchoring our expectation on the same formula means we flag the same discrepancy the seller would dispute.
- **Compute-from-source matches the codebase.** Expected Net is derived from the Platform Fee Profile (`ComputeExpectedNet`), Available is derived from the ledger, the Effective Price is resolved from Promotion Lines — none are stored redundantly per row when a source of truth exists. Expected shipping is the same: the catalogue already holds weight + dimensions, so we compute, not store.
- **Fail-safe over fail-wrong.** A guessed rate would mis-flag real money; a missing rate simply means "can't assess this Order" — consistent with ADR 0005 (never silently default a money input) and with the Claim being a *prompt to verify*, not an automated recovery.
- **Over-estimating volumetric is the safe bias for a prompt.** Tightening the model later (real consolidated-parcel dims) only lowers expected → flags *more*; it never retroactively turns a past flag into a false one in a way that corrupts data, because Claims are reviewed by a human before filing.

## Consequences

- `expected_shipping_rate`'s JSON shape (`up_to_g` / `fee`-satang tiers) is now a contract: a Shop Settings affordance to author it is needed (MVP may seed it via import/tinker; a Filament field is a follow-up). Until set, the Shop simply never auto-flags shipping overcharges.
- New Actions: `ComputeExpectedShipping(Order): ?Money` (null when inputs are missing) and `FlagShippingOverchargeClaim(Order)`, wired into the Accounting-Entry import flow (where `shipping_seller_paid` lands), idempotent per Order, creating the Claim via `CreateClaim` (ADR-0021-era kernel, Issue #79).
- The per-unit-additive volumetric aggregation is a **documented limitation**; consolidated-parcel volumetric (real box dimensions) is a future refinement.
- `TOLERANCE` is a documented constant, not yet per-Shop — revisit if false-positive/negative rates warrant it.

## Considered options

- **Store an expected-shipping amount per Order at import.** Rejected — redundant state to maintain when the catalogue already carries weight/dimensions; contradicts the compute-from-source posture of Expected Net / Available / Effective Price.
- **Actual-weight-only (ignore volumetric).** Rejected — bulky-but-light parcels (the classic volumetric case) would be systematically under-expected and over-flagged, and it contradicts how every target courier actually bills.
- **Consolidated-parcel volumetric now (max-L, max-W, sum-H per Shopee's multi-item rule).** Rejected for MVP — we do not model the real packed box; the per-unit-additive sum is a simpler, monotonic approximation that errs toward not false-flagging. Revisit when packed-parcel dimensions are modelled.
- **Percentage tolerance.** Rejected for MVP simplicity in favour of an absolute satang constant; revisit alongside moving `TOLERANCE` to Shop Settings.
