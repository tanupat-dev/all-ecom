---
name: all-ecom-standard-first
description: Use when a technical decision commits to a design-shape whose wrong choice would force real rework or cause a defect — correctness, rollback/atomicity, money or stock, the ordering of side effects (before/after a write or event), sync vs async, atomic vs eventual, fail-fast vs coerce, or a persisted/published contract. Fires equally on a silent confident pick AND an explicit "best practice / idiomatic / recommended" claim — both rest on an unverified assumption. Does NOT fire when the choice is locally reversible with a trivial refactor and no behaviour change — internal naming, in-memory structure (Map vs array), formatting, a route split with no external consumer.
---

# All-Ecom Standard-First

The whole domain model was built this way: **research the industry standard before locking a
costly-to-reverse decision** (14 ADRs came out of it). A confident guess and an "it's best practice"
claim are the same risk — an unverified assumption about money, stock, or ordering.

## When it fires

A design-shape where being wrong costs real rework or a defect: correctness, atomicity/rollback,
**money or stock**, side-effect ordering (does stock move before or after the write/event?),
sync vs async, atomic vs eventual, fail-fast vs coerce, or anything persisted/exported (a DB shape,
an Excel contract, an API others read).

## Procedure

1. **Check the ADRs first** — `docs/adr/0001–00NN` and `CONTEXT.md` may already have decided it. If so, follow it (or, if you think it's wrong, that's a *new* ADR superseding the old — see ADR 0008→0012).
2. **Name the assumption** out loud.
3. **Research the standard** (web) — how do mature systems do this? Get ≥2 concrete options.
4. **Compare and pick with a reason**, weighted to *this* product (Thai small-seller SaaS, Excel-only, no-API, immutable ledger).
5. If the decision is **hard to reverse + surprising + a real trade-off** → **write an ADR** (the three-part test). Otherwise record it inline and move on.

## Extra scrutiny for money / stock / ordering

- Is the write **atomic** (one DB transaction — e.g. append Stock Movement + update the denormalized balance together)?
- Does the **side-effect order** preserve the invariant (RESERVE before SHIP; bundle expands to components atomically; cash refund hits the Shift)?
- Money is **integer satang**, never float. Available may go **negative** (oversell) — don't "fix" it.

Don't ship the guess. A reversible naming choice doesn't need this; a stock-ledger ordering choice does.
