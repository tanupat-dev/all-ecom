---
name: all-ecom-business-rules-check
description: Use before designing or planning any feature that touches money or stock — pricing, Effective/Deal/List Price, discounts and promotions, fees and accounting, COGS/profit, payments and tenders, stock movements, available/oversell, bundles, returns/refunds, payout/reconciliation, or any customer-facing amount. Read the relevant CONTEXT.md terms + ADR first and reconcile the plan against them. Do NOT skip because a rule seems obvious or a framework default looks reasonable. Not needed for pure UI/content work that displays no money and changes no stock or payment logic.
---

# All-Ecom Business-Rules Check

`CONTEXT.md` is the glossary and rules-of-record; `docs/adr/0001–00NN` are the decisions behind them.
For anything touching money or stock, **read the relevant terms first and reconcile the plan against
them** — the rules here were hard-won and a reasonable-looking framework default often violates one.

## Before designing, read the terms that apply

Match the feature to its glossary terms + ADR and read them:
- **Pricing:** List Price (Variant-level, uniform), Deal Price, **Effective Price** (the anchor for all financial calc), Promotion / Promotion Line, Manual Discount (POS, ≠ Promotion).
- **Stock:** Stock Movement (immutable ledger, 9 actions), **SHIP order-aware**, On-Hand/Reserved/**Available (can go negative)**, Buffer, Oversell, per **`(Variant, Location)`** (ADR 0013), **Bundle** expansion (ADR 0014).
- **Orders/returns:** Order Status (per-channel subset), Return (marketplace, line-level, ADR 0006), **POS Return = negative Order** (ADR 0009), fail-loud mapping (ADR 0005).
- **Money in/out:** Payment (POS tenders), Shift cash reconciliation, Accounting Entry (**cycle-aware**, ADR 0007; **POS has none** — P&L = Payment − COGS), Cost Price (history → COGS at sale date), Reconciliation / Settlement / Hold Period, Claim.

## Reconcile

- Does the plan respect each applicable rule? If a **framework default conflicts** (float money, soft-delete stock, sum-the-ledger-on-read, single stock pool, auto-cancel oversell) — **the rule wins**; flag and follow the rule.
- If the feature needs a rule that **isn't documented yet** → don't invent silently: decide it via `all-ecom-standard-first`, then **document it** (CONTEXT.md term and/or an ADR) *before* coding.

The cost of getting a money/stock rule wrong is corrupted seller data — treat this check as mandatory, not overhead.
