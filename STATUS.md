# STATUS — where we are & what's next

> Living handoff + checklist. **Read this first** at the start of a session; **update + commit it**
> at the end (skill `all-ecom-handoff`). It is the live pointer — the plan is `docs/ROADMAP.md`,
> the decisions are `docs/adr/`, the rules are `CONTEXT.md` / `CONVENTIONS.md`. Don't restate them here.
>
> Tags: **[AFK]** = an agent can do it end-to-end (loop-runnable) · **[HITL]** = needs Tan's decision first.

_Last updated: 2026-06-11 (session 3)_

## ✅ You are here

- **Design phase complete & on GitHub** (`tanupat-dev/all-ecom`, private, branch `main`).
  - `CONTEXT.md` glossary, ADR 0001–0014, `docs/ROADMAP.md` (Phase 0–8), `CONVENTIONS.md` (stack locked: Laravel + Filament + Livewire + Postgres).
  - 10 project skills under `.claude/skills/`.
  - `ref doc/` (real platform exports, PII) is gitignored — local reference only.
- **No application code yet** — Phase 0 has not started.

## ▶️ Next session: start here

1. **[HITL] Pick the Windows PHP runtime** — Laravel Herd (simplest) vs Sail/Docker (Linux-parity with Hetzner prod). Gates the scaffold. → lock in `CONVENTIONS.md`.
2. Then **Phase 0 scaffold** (first [AFK] slice once runtime is chosen).

## ☐ Backlog — Phase 0 (cross-cutting, decide-once)

- [ ] **[HITL]** Windows PHP runtime: Herd vs Sail → record in CONVENTIONS.md
- [ ] **[AFK]** `laravel new` + Filament + Livewire + Alpine + Postgres into this repo; `/up` health route confirmed
- [ ] **[AFK]** Money primitive — integer **satang** value object / cast; THB only; no float
- [ ] **[AFK]** Tooling/CI gates — Pint, Larastan **level max**, Pest; CI fails on any
- [ ] **[AFK]** Tenant table + `tenant_id` convention + `BelongsToTenant` global scope + **Postgres RLS** + app connects as **non-owner** DB role (ADR 0011)
- [ ] **[AFK]** Queue + worker (Supervisor/equiv) + **central bulk-import pipeline** skeleton (streaming + chunked upsert)
- [ ] **[AFK]** Cross-tenant **isolation test** harness (the mandatory suite — ADR 0011)
- [ ] **[AFK]** Audit columns (`created_at/updated_at/created_by`) + audit-log for admin-gated actions

## ☐ Backlog — Phase 1 (Catalog + Stock kernel) — after Phase 0

See ROADMAP Phase 1 for the full slice list. Headlines: Product/Variant + Master SKU + barcode · **Location** (ADR 0013, stock per `(Variant, Location)`) · **Bundle/BOM** (ADR 0014) · Cost Price history · Stock Movement ledger (9 actions, `location_id`, order-aware SHIP) · denormalized balances (never SUM ledger) · Stock Adjustment + Transfer. All **[AFK]** (pinned by ADRs); raise **[HITL]** only if a rule is missing.

## ❓ Open decisions / parked (HITL)

- PHP runtime (above) — the only blocker for Phase 0.
- GitHub **Issues backlog** (to-issues + triage labels `ready-for-agent`/`ready-for-human`): adopt when slices need parallel/loop execution. Not yet.
- MEDIUM/LOW skills not yet written (optional, just-in-time): `pre-commit-verify`, `default-decisions`, `pre-code-orchestration`, `vendor-template-audit` (Phase 4), `commit-flow`, `db-reset`, `diagnose` (first bug), `prototype`.

## 🔗 References

- Plan: `docs/ROADMAP.md` · Decisions: `docs/adr/0001–0014` · Rules: `CONTEXT.md`, `CONVENTIONS.md`
- Skills: `.claude/skills/` (engineering-process, standard-first, business-rules-check, security-check, search-before-write, consistency-sweep, tdd, verify, local-server-hosting, handoff)
- Last commits: `1a9d423` (vertical-slice + tdd skill), `e397d58` (design foundation)
