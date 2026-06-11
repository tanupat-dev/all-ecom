# Minimal two-role access control (admin / cashier), reversing the single-user MVP scope

---
Status: **superseded by ADR 0012** (custom per-Tenant RBAC). The two roles survive only as the seeded *default* Roles; the "fixed two roles, no custom roles, no per-permission editing" decision below is no longer in effect.
---

The MVP gains a lightweight access model: a **User** entity with exactly **two preset Roles** — `admin` (owner/manager: full access including cost/profit) and `cashier` (POS checkout + own Shift only, no cost/profit visibility, void/refund/discount gated behind Admin approval). Multiple Users may hold each Role. There are no custom roles, no per-permission editing, and no manager/supervisor tiers. This **reverses** the earlier decision to ship a single-user MVP with no roles.

## Why

- **The real operating pattern forces it.** The owner runs the online side (marketplace import/export) and sees whole-store inventory, cost, and profit; counter staff only ring up walk-in POS sales. Once POS supports multiple people across multiple shifts, "everyone sees everything" means handing cost/margin and accounting to hourly counter staff — not acceptable, and not the small-retail norm.
- **It's the small-retail standard, minimally.** Mature POS systems (Loyverse, Lightspeed, Square) ship a 4-tier hierarchy, but small shops use just owner-vs-cashier. We adopt the two endpoints and skip the middle.
- **One gate does most of the work.** Following Loyverse's model, a single "view cost" capability hides cost, COGS, gross profit, and margin everywhere at once — so the sensitive split is one boolean, not a permission matrix.
- **Cash accountability needs identity anyway.** POS Shift reconciliation (cash over/short) is meaningless unless the system knows *which* person held the drawer — so a User identity per Shift is required regardless of the visibility split.

## Considered options

- **Keep single-user, no roles (the prior decision).** Rejected: incompatible with multi-cashier POS and exposes cost/profit to counter staff.
- **Full per-permission RBAC / 4-tier hierarchy.** Rejected for MVP: more configuration surface than a small seller needs; the two endpoints cover the real case.

## Consequences

- Adds a `User` table (name, login credential, `role ∈ {admin, cashier}`, active flag) at the business level — not Shop-scoped in MVP.
- The "view cost" gate must be honoured consistently across UI and reports; cost/profit fields are stripped for Cashiers everywhere, not just hidden on one screen.
- Void / refund / discount at POS need an Admin-approval path (e.g. Admin PIN/override) — the only places a Cashier touches an Admin-gated action.
- A future move to finer-grained roles (e.g. a "store manager" who sees reports but not margin) is an additive change, not a rewrite.
