# POS returns are linked negative-line Orders, not Return entities

A face-to-face POS refund/return is modelled as a **new POS Order with negative line quantities**, linked to the original sale via `ref_order_id` (bound to its `receipt_no`). This is deliberately **different** from a marketplace/social buyer return, which stays a dedicated **Return entity** (header + lines with `platform_return_id`, Return Sub-Status, Inbound Scan, and platform-closure lifecycle — see ADR 0006). The system therefore has **two return mechanisms, split by channel**.

## Why

- **The two channels have fundamentally different shapes.** A marketplace return has an *authorization + in-transit gap*: the buyer requests, the platform authorizes, goods travel back over days, and closure is the platform's to decide. That gap is exactly what the Return entity's lifecycle (Sub-Status, Inbound Scan, `ReturnClosed` detection) exists to track. A POS return has **no gap** — the goods cross the counter and the cash leaves the drawer in one instant — so none of that lifecycle applies.
- **It matches industry standard.** Microsoft Dynamics 365 Commerce writes POS returns as "sales orders with negative lines"; the RMA/return-document pattern (NetSuite, Oracle) is reserved for the authorized, in-transit case. We follow the same split.
- **It reuses the POS machinery we already have, sign-inverted.** A POS sale is already just an Order (positive lines → `SHIP`, positive Payment in, `receipt_no`). A POS return is the same Order shape flipped: negative lines → `RECEIVE`, negative Payment out (cash refunds feed the Shift's `expected_cash`), linked to the original sale. No new entity, no marketplace fields forced onto POS.
- **Exchanges fall out for free.** An exchange is a negative Order (the return) plus a positive Order (the new sale); the net is the price difference — the standard way to handle it.

## Considered options

- **Reuse the Return entity for POS in a "simplified POS mode"** (platform fields null, skip Inbound Scan/Sub-Status). Rejected: it contaminates the marketplace Return with a second, lifecycle-less mode and fights the fact that a POS sale is already an Order. The negative-line Order is the cleaner, standard fit.
- **One unified return mechanism across all channels.** Rejected: the marketplace gap genuinely needs the lifecycle the POS counter does not — forcing one model either over-burdens POS or under-serves marketplace.

## Consequences

- A POS return is detected by its negative line quantities / negative total and its `ref_order_id` back to the original sale; reporting must treat it as a refund, not a sale.
- Returns must reference an original POS Order (fraud + inventory control). No-receipt returns and store credit are out of MVP scope.
- Refunds are Admin-gated (see ADR 0008 / Role) — a Cashier needs Admin approval to issue one.
- The seller-facing model now has two return concepts to understand (Return entity for marketplace/social; negative Order for POS) — an accepted cost, justified by the channels' different real-world shapes.
