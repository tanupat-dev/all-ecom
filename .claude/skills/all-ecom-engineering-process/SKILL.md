---
name: all-ecom-engineering-process
description: Use BEFORE implementing any non-trivial change — a new feature, a refactor, a multi-file change, anything over ~100 lines, or any migration/seed/Job. Carries the plan-first procedure (propose file structure + Action signatures, get approval before coding), dependency-first design for clusters (whole-slice model → dependency graph → topological commit order, per ROADMAP), the idempotency standard for migrations/seeds, and Laravel structure/size targets. NOT for a trivial one-line edit, a docs/content change, or a mechanical rename.
---

# All-Ecom Engineering Process

Plan before code. This matches spec-driven development (plan → implement → verify) and the
repo's whole reason for `docs/ROADMAP.md`: build each phase **once**, never come back to rework it.

## Plan-first (mandatory for non-trivial work)

Before writing code, propose and get a quick approval on:
1. **Which ROADMAP phase / ADR** this implements (if none fits, you're probably out of scope — stop and ask).
2. **Files** that change or are added.
3. **Action / class signatures** (1 business action = 1 Action class, `handle()`), plus the Form Request / Policy / Job / Filament Resource / Livewire pieces it needs (see `CONVENTIONS.md`).
4. **Edge cases** — especially money/stock/tenancy ones (negative Available, bundle expansion, fail-loud mapping, cross-tenant).

## Respect the ROADMAP order — never rework an earlier phase

- Build in phase order; do **not** bake a later-phase concern into an earlier phase, and **never modify the Phase-1 stock/catalog kernel to satisfy a later need** — that is the exact rework the ROADMAP exists to prevent. If you think you must, that's a `standard-first` / ADR moment, not a quiet edit.
- For a **cluster** of related work: model the whole slice first, draw the dependency graph, order the commits **topologically** (foundation → consumer). One logical unit per commit.

## Idempotency standard (migrations & seeds)

- Migrations: reversible (`down()`), safe to re-run, **no full-table `UPDATE` without a `WHERE`**, every domain table gets `tenant_id` + the RLS policy + composite indexes led by `tenant_id` (ADR 0011/0013).
- Seeds: idempotent — check-before-insert / `upsert`, re-runnable without duplicating (mirrors the import idempotency the domain already requires).

## Laravel structure & size

- Business logic lives in **Actions**, never in controllers/models/Livewire components. Controllers thin; Models = relations + casts + scopes.
- Money = integer **satang**; stock per `(Variant, Location)`; every model `BelongsToTenant`. (CONVENTIONS domain rules 1–9.)
- Keep Actions and methods small; split a class/file when it grows past comprehension. Larastan level-max must stay green.

## After coding

→ `all-ecom-verify` (cheapest test layer) and `all-ecom-consistency-sweep` (update every doc/comment the change made stale) **before** the commit.
