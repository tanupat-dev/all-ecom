# Multi-location inventory: stock is tracked per (Variant, Location)

Stock is held at a **Location** (a physical place that holds inventory — a storefront, a stockroom, a warehouse), not in one pool per business. Every Stock Movement carries a `location_id`, and On-Hand / Reserved / Available / Buffer / Oversell are all computed **per `(Variant, Location)`**; business-wide quantities are the aggregate (sum) across Locations. Each Tenant auto-provisions **one default Location**, so a single-site seller never has to think about it, but the dimension exists from Phase 1 so it never has to be retrofitted.

## Why

- **The product already implies multiple physical sites.** We support multiple `pos` Shops (physical stores) plus marketplace fulfilment — if a seller runs two storefronts, each holds its own real stock, and a single shared pool would be simply wrong (a sale at store A can't draw from store B's shelf). Multi-location is the standard OMS foundation: "track at the location level, not just aggregate."
- **Retrofitting `location_id` later is among the most expensive reworks** — it touches the ledger, every balance, every export, and oversell — exactly the rework the roadmap exists to avoid. Same logic as `tenant_id` and Register: design the dimension in now, default it to one.
- **Single source of truth across locations** (one ledger, location-stamped) is what makes accurate available-to-promise possible — the documented best practice.

## How

- **Location** entity belongs to a Tenant: `name`, `type` (store / warehouse), `active`. One default seeded per Tenant.
- **Stock Movement** gains `location_id`; the denormalized current quantities are keyed `(tenant_id, variant_id, location_id)`. The Phase-1 "never SUM() the ledger at runtime" rule now applies per location.
- **Transfer** between Locations = a linked **pair** of Movements (out at source, in at destination), driven by a Stock Adjustment of type transfer. No stock is created or destroyed — it moves.
- **Channel → fulfilment Location (designated-location routing, the simplest standard):** each Shop is assigned a fulfilment Location. A marketplace Shop's stock export writes that Location's Available; an imported order deducts from it. A `pos` Register sells from its store's Location. (Proximity / availability / split-shipment routing is a later refinement, not MVP.)
- **Buffer / Oversell / Available** are per `(Variant, Location)`. Available export per Shop = its fulfilment Location's Available (clamped at 0).

## Consequences

- Composite keys/indexes extend to `(tenant_id, variant_id, location_id)`.
- A seller with one site sees no extra complexity (one default Location, hidden).
- Pooling several Locations behind one channel, and smart order routing, are **additive** later — the per-location ledger already supports them.
- Bundles (ADR 0014) resolve their component availability **within a Location**.
