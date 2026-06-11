# Channel model boundary: share the core record, split only where channel behaviour genuinely diverges

When modelling a concept that appears across channels (`marketplace`, `social`, `pos`), the governing rule is: **keep one shared core record, and introduce a channel-specific model only for the dimension where the channels' real-world behaviour actually differs.** Do not separate per channel by default, and do not force-merge things whose real behaviour differs. This is the principle behind ADR 0002 (one unified Order) and ADR 0009 (POS returns split off as negative-line Orders); this ADR states it explicitly so future work doesn't drift toward either extreme.

## Why

- **Unified commerce shares the core on purpose.** The industry trend (Shopify, SAP, Salesforce, commercetools) is a single shared data model for inventory, product, order, and customer — that *is* the value of unified commerce over siloed omnichannel. For a small seller the whole point of this product is **one view of the business**: one stock pool, one combined P&L, one order list across channels. Separating everything would destroy that.
- **DDD says split by aspect, not by storage.** The same concept legitimately means different things in different contexts (a Product is SKU/storage to Inventory but price/attributes to Ordering). The right response is a context-specific *view/concern*, not a duplicate table per channel.
- **Integration debt compounds.** Every duplicated model adds reconciliation plumbing, failure points, and future-change cost — the opposite of what a lean small-seller tool should carry.

## How it has been applied

- **Shared core:** Stock / On-Hand / Available (single source of truth), Product, Variant, List Price, Order, Order Line. The Order is unified with a `platform_type` discriminator (ADR 0002); channels differ only in *how an Order enters* and *which lifecycle states apply*.
- **Split where behaviour truly diverges:**
  - **Returns** — marketplace/social buyer returns have an authorization + in-transit *gap* (Return entity with Inbound Scan / Sub-Status / platform-closure lifecycle, ADR 0006); a POS return has no gap, so it is a negative-line Order (ADR 0009).
  - **Payment** — money mechanics differ: marketplace money is platform-settled (tracked via Accounting Entry / Actual Net), POS money is collected at the counter (Payment with tenders). So Payment is POS-specific.
  - **Promotion vs POS manual discount** — a scheduled, per-Listing Promotion (Deal Price) is a different mechanic from an ad-hoc discount a cashier applies at the counter; they are separate concepts, not one reused entity.
  - **Shift / Register / Receipt** — POS-only; no marketplace analogue.

## Consequence

The decision for any *future* cross-channel concept is a single question: **does the channels' real-world behaviour for this concept actually diverge?** If no, extend the shared core. If yes, add the channel-specific model only for the diverging dimension. "Separate everything except stock" and "force everything into one model" are both rejected.
