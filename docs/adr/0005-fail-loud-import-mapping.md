# Importers fail loud on any unmapped Platform value — never silent-default

Every point where an importer maps a raw Platform value into one of our canonical sets is **fail-loud**: an input with no entry in the mapping table is surfaced as an explicit, visible import error ("ระบบไม่รองรับ — unsupported value") and the affected record is held for the seller to resolve. We **never** fall back to a default, a catch-all bucket, or a best-guess when the input is unrecognised. This applies at minimum to:

- **Order Status** — native Platform status → canonical 9-value Order Status. Unknown native status → Order held, not defaulted.
- **Return Reason** — Platform reason text → buyer-fault / seller-fault bucket. Unknown reason → flagged for manual classification, not silently bucketed (which would mis-flag Claims).
- **Platform SKU lookup** — `(Platform, Shop, Platform SKU)` → Variant. No match (e.g. an order for a Variant not yet in the system) → import error on that line, not a dropped or orphaned Order Line.
- **Fee Category** — Platform fee field → one of the 8 canonical Fee Categories. An unrecognised fee field → surfaced (it may land in `other` only by an explicit, reviewed mapping decision, never automatically).

## Why

- **The seller has no API — Excel exports are the only source, and Platforms change them without notice.** A new Shopee order status, a reworded Lazada return reason, a renamed TikTok fee column will appear in an export one day with zero warning. (This is already real: the Lazada Thai return-reason list is unconfirmed, and reason texts have changed across years.)
- **Silent defaults corrupt invisibly.** If an unknown status silently maps to, say, `กำลังขนส่ง`, or an unknown reason silently buckets as buyer-fault, the lifecycle / Claim / reconciliation logic keeps running on wrong data and the seller never knows. The prior system this user was burned by failed exactly this way — trusting an upstream value it shouldn't have. The whole point of mapping into a canonical model (ADR 0002/0003) is defeated if the mapping guesses.
- **Held records are cheap; corrupted money math is not.** Stopping on an unknown value costs the seller one manual review. Mis-flagging a Claim or mis-stating Actual Net costs real money and trust.

## Consequences

- Every importer needs an explicit, versioned mapping table per Platform and a defined "held / needs-attention" state surfaced in the UI — not just a parse step.
- Onboarding a Platform's new status/reason/fee is a deliberate act (add a mapping row), which is the intended friction.
- A noisy export (many unmapped values at once) can produce a batch of held records; the UI must let the seller resolve them in bulk, or the fail-loud behaviour becomes a usability problem.
- `other` as a Fee Category is reachable only through an explicit mapping entry, never as an automatic fallback — otherwise it would silently re-introduce the very silent-default this ADR forbids.
