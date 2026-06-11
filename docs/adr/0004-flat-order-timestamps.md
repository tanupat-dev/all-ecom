# Flat milestone timestamps on Order, upserted from snapshot imports — no status-history log

Each Order carries a fixed set of **nullable milestone timestamp fields** — `created_date`, `paid_date`, `shipped_date`, `delivered_date`, `completed_date`, `cancelled_date` — populated directly from the corresponding columns in each Platform's order export. Imports are **upserts** keyed on Order ID: re-importing the same Order overwrites its current status and fills any newly-populated timestamp, but **never nulls out** a field that was previously set (defensive merge). We do **not** keep an append-only log of status transitions for MVP.

`delivered_date` and `completed_date` are distinct events (goods arrived at destination vs. order finalised after the buyer-confirm/return window), and the Hold Period anchors on whichever one the Platform's payout clock uses — Shopee on `completed_date`, TikTok/Lazada on `delivered_date` (see `payout_anchor` per Shop).

## Why

- **Imports are daily snapshots, not an event stream.** The seller has no API; they upload an Excel export perhaps once a day. Between two uploads an Order can jump several statuses (e.g. `รอแพ็ค` → `เสร็จสมบูรณ์`). We never observe the intermediate transitions live.
- **The export already carries cumulative timestamps.** Each export row fills *all* milestone columns that have occurred so far, regardless of the Order's current status. So skipping statuses loses nothing: we read each timestamp from its own column rather than inferring it from an observed transition. This is precisely what makes flat fields robust to status-skipping — a transition log built from snapshots would have gaps, flat columns do not.
- **Payout calc needs the value, not the history.** `expected_payout_date = anchor + hold_period` needs only the latest known anchor timestamp. A full transition history would be carried weight with no MVP consumer.
- **Delivered ≠ Completed, and platforms disagree on which they expose.** TikTok/Lazada give a `delivered` timestamp column but not `completed`; Shopee gives `completed` but exposes `delivered` only inside a status string. Conveniently each Platform supplies a timestamp for exactly the milestone its own payout clock anchors on, so a per-Shop `payout_anchor` pointer resolves the Hold Period correctly everywhere.

## A status history is derivable, not lost

Keeping flat fields instead of an event log does **not** forfeit status history. Because each snapshot row carries the Platform's own cumulative milestone timestamps, the flat fields already encode a compressed, accurate history — a timeline view can be reconstructed retroactively from the timestamp columns at any time, with no data loss, regardless of how infrequently the seller uploads (a once-a-day upload still reads the Platform's exact stamped times, not the import time). The only genuine gaps versus a full event log are: (1) milestones the Platform exposes **only as a status string with no timestamp column** (e.g. Shopee `delivered`, TikTok `completed`) — their *occurrence* is known but their *time* is only as precise as the upload cadence (±1 day); and (2) an audit of which import file caused each change. Neither is worth an event log in MVP; if a real history table is wanted later, it can be back-filled from the existing milestone timestamps.

## Consequences

- No audit trail of *when* an Order moved between statuses — only the timestamps the Platform itself stamped. If a status-change history is needed later (disputes, analytics), it must be added as a separate append-only log, and back-filling pre-existing Orders will be lossy.
- The importer must implement defensive merge (`no-null-overwrite`) carefully — a later export that omits an earlier timestamp must not erase it.
- Milestone fields are nullable and Platform-dependent; any consumer (SLA reports, timeline UI) must tolerate gaps (e.g. Shopee Orders have no `delivered_date`).
- Reconciliation depends on the per-Shop `payout_anchor` being set to the correct milestone for each Platform; a misconfigured anchor silently shifts every `expected_payout_date`.
