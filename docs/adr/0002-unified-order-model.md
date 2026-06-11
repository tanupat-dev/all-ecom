# Unified Order model across Platform types

Orders from all three Platform types — `marketplace` (Shopee/Lazada/TikTok), `social` (LINE/IG/Facebook), and `pos` (physical storefront) — live in a single `orders` / `order_lines` table, distinguished by `Platform.platform_type`. Platform-specific columns (e.g., `tracking_number` for marketplace, `cashier_id` / `payment_method` for POS, manual order entry for social) are nullable on the shared table.

## Why

The Order lifecycle, return flow, Stock impact, and reconciliation logic are ~80% identical across all three types — splitting tables would force duplicate code paths in every reporting / status / movement query. The differences are concentrated in **how Orders enter the system** (Excel import / manual entry / POS UI) and **which lifecycle states apply**, not in the data shape itself.

## Escape hatch

If POS-specific or social-specific fields grow beyond **~8 columns** that are NULL for other types, split them into side tables (`pos_order_details`, `social_order_details`) joined via `order_id`. This preserves the shared base model and the cross-type queries while isolating divergent fields. Do not split into fully separate `pos_sales` / `marketplace_orders` tables — the reporting cost is too high.

## Considered and rejected

- **Separate tables per Platform type** (`marketplace_orders`, `pos_sales`, `social_orders`). Cleaner schemas but every cross-channel report becomes a UNION, every return / stock / claim handler has 3 code paths. Rejected.
