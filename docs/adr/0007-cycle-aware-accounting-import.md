# Accounting import is cycle-aware: idempotent within a statement cycle, append across cycles

An Order's financial line items are keyed by `(ref_order_id, statement_cycle, source_field)`. Re-importing the **same** statement cycle replaces that cycle's line items for the Order (so re-uploading an overlapping file never double-counts). A **new** statement cycle appends its line items. The Order's Accounting Entry — and its Actual Net — is the sum across **all** cycles. This replaces the earlier "one Accounting Entry per Order, immutable once imported" rule.

## Why

- **Imports overlap.** Sellers export by date range and re-upload; the same Order appears in multiple files. The system must dedupe rather than create duplicates — the same idempotency requirement as Orders and Returns.
- **An Order's money is split across statement periods.** Accounting files are issued per cycle (Lazada `รหัสรอบบิล` / `ระยะเวลาใบแจ้งยอด`; Shopee/TikTok per payout). A sale settles in one cycle; a return deduction, a fee correction, or a withheld-tax adjustment can post weeks later in a different cycle. The later file contains **only** that cycle's lines, not the Order's full history.
- **Whole-Order replace would destroy data.** "On re-import, replace all of an Order's accounting" looks simple but is wrong: importing a later cycle that holds only a refund line would wipe the original sale line and leave just the refund — corrupting Actual Net. Cycle is the correct replace boundary.
- **Per-line transaction IDs aren't reliably present.** Professional reconcilers (e.g. Adyen) dedupe at the individual-transaction-ID level, but the seller-facing Excel exports don't reliably carry a stable per-row ID (Lazada exposes only the cycle id, not a row id). Cycle-level idempotency is the achievable approximation: stable enough to dedupe, coarse enough to not need row IDs we don't have.

## Consequences

- An Accounting Entry is an aggregate over its cycles, not a single immutable import. Actual Net and Reconciliation Status recompute whenever a new cycle for the Order arrives.
- `statement_cycle` must be parsed/derived per Platform — trivial for Lazada (`รหัสรอบบิล`), inferred from the payout/settlement identifier for Shopee/TikTok. A Platform that exposes neither a cycle nor a row id forces a fallback (treat the file's period as the cycle key) and is a fail-loud candidate (ADR 0005) if even the period is ambiguous.
- Reconciliation must tolerate an Order's totals changing over time as late cycles post; a previously `paid_ok` Order can move to `paid_mismatch` when a later deduction arrives — which is the desired behaviour, not a bug.
- Withdrawal (Wallet→Bank) is still not modelled (no Payout entity); cycles track money *settled to the Order*, not money moved to the seller's bank.
