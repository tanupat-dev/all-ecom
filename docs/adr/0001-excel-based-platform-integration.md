# Excel-based platform integration (no API)

Our target users are small sellers who can't get API access from Shopee / Lazada / TikTok. The system integrates by **batch Excel/CSV import** of platform reports (orders, accounting, returns) and **Excel export** in each platform's native format for the user to upload back. There is no real-time sync.

## Consequences

- **Oversell is unavoidable in a window.** Stock numbers on platforms lag behind reality between exports; the Buffer mechanism reduces but does not eliminate this. Accept and design for it (re-import frequently, surface mismatches, allow Inbound Scan to ground-truth physical state).
- **One importer per platform per file type.** Shopee splits orders into `All Order` / `Cancelled` / `Failed Delivery` / `Return Refund`; Lazada uses a transaction journal; TikTok has a 66-column per-order sheet. There is no shared schema — every importer maps the platform's columns into our canonical model.
- **Status transitions are imported, not commanded.** We never tell a platform "ship this order" — the user does that in the platform UI. We only observe and reconcile.

## Considered and rejected

- **API integration.** Faster, real-time, no oversell window. Rejected because our users can't access APIs (the entire reason this product exists). Revisit only if we serve a different segment.
