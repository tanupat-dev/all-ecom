# all-ecom — Agent guide

Multi-tenant SaaS for small Thai sellers to manage stock / orders / accounting / promotions
across marketplaces (Shopee/Lazada/TikTok via **Excel import/export — no API**) plus a physical POS.

## Read first (sources of truth — point here, don't restate)
- **`CONVENTIONS.md`** — structure / patterns / quality gates. **Read before writing any code.**
- **`CONTEXT.md`** — domain glossary (Tenant, Variant, Stock Movement, Shift, …). All terms come from here.
- **`docs/adr/0001–0018`** — costly-to-reverse decisions. To deviate, write a NEW ADR that supersedes — never edit one in place.
- **`docs/ROADMAP.md`** — build order (dependency-first, no-rework). Currently **Phases 0–3 complete** — the POS storefront loop is shippable (sell / split tender / discount / park / receipt / return / blind close). Next = **Phase 4 Marketplace import** — Issues #29–#37 published; build from the open `ready-for-agent` queue (the per-platform importers #32–#34 are `ready-for-human`, blocked on restoring `ref doc/`).

## Stack (locked — changing it requires an ADR)
Laravel 13 · Filament 5 (back-office) · Livewire 4 + Alpine (POS) · PostgreSQL · deploy Forge/Ploi → Hetzner.
Framework majors track the security-support window (ADR 0017) — upgrading a major is routine maintenance.
Dev = WSL2 Ubuntu native · CI = GitHub Actions `ubuntu-latest`.

## Iron rules (details live in the ADRs — never violate)
- **Money = integer satang, never float · THB only** (ADR 0015)
- **Every domain model carries `tenant_id` + `BelongsToTenant` global scope + Postgres RLS** (ADR 0011). Cross-tenant isolation test is a must.
- **Stock = append-only ledger only** — never update/delete · per `(Variant, Location)` · never `SUM()` at runtime (ADR 0003/0013)
- **Imports are fail-loud** — an unmapped value is held + surfaced as an error, never silently defaulted (ADR 0005)
- **RBAC** — gate every action on a named Permission via a Policy; never `if role == 'admin'` (ADR 0012)
- Business logic = **Action class** (`app/Actions`) — never in a Controller / Model / Livewire component.

## Backlog / "what's left"
= **GitHub Issues** on `tanupat-dev/all-ecom` via the triage label state machine (not a repo file).
`ready-for-agent` = AFK loop-runnable · `ready-for-human` = needs a person. Use the `all-ecom-triage` skill.

## Skills (invoke at the matching moment — don't skip)
Before non-trivial work → `all-ecom-engineering-process` · writing a slice → `all-ecom-tdd` ·
before creating a construct → `all-ecom-search-before-write` · before designing money/stock → `all-ecom-business-rules-check` ·
before locking a costly decision → `all-ecom-standard-first` · before committing sensitive code → `all-ecom-security-check` ·
verifying a change → `all-ecom-verify` · **every commit that changes behaviour / a name / a term → `all-ecom-consistency-sweep`** ·
end of session → `all-ecom-handoff`.
