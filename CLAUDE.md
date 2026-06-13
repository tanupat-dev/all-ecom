# all-ecom — Agent guide

Multi-tenant SaaS for small Thai sellers to manage stock / orders / accounting / promotions
across marketplaces (Shopee/Lazada/TikTok via **Excel import/export — no API**) plus a physical POS.

## Read first (sources of truth — point here, don't restate)
- **`CONVENTIONS.md`** — structure / patterns / quality gates. **Read before writing any code.**
- **`CONTEXT-MAP.md`** — domain glossary, split into **bounded contexts** under `docs/context/` (Tenancy, Catalog, Inventory, Orders, Fulfilment, Pricing, Accounting, Claims, POS). All terms come from there; load only the relevant context. (`CONTEXT.md` is now a redirect stub for legacy `CONTEXT.md: Term` pointers.)
- **`docs/adr/0001–0022`** — costly-to-reverse decisions. To deviate, write a NEW ADR that supersedes — never edit one in place.
- **`docs/ROADMAP.md`** — build order (dependency-first, no-rework). Currently **Phases 0–9 complete** (all dev phases done; only ops #48 — R2 bucket, prod-only — remains). Phase 8 (Claims; Issues #78–#85) shipped: Return Reason fault classification, the Claim kernel (ClaimType/ClaimStatus 6-state two-stage lifecycle, CreateClaim, claim.view/manage + ClaimPolicy), return_fee auto-flag from a seller-fault Return, the lifecycle transition state-machine, the Evidence Checklist, the append-only Claim Timeline, the Claim Filament Resource, and the `shipping_overcharge` auto-flag with **ADR 0022** (expected shipping computed from catalogue weight/dimensions — max(actual, volumetric) ÷ 5000, tiered `expected_shipping_rate`, ฿5 tolerance, fail-safe) wired into UpsertAccountingCycle. Phase 7 (Promotions + Margin, **ADR 0021**: Promotion + Promotion Line with the Line as the Deal Price authority and `ListingVariant.deal_price` as its cache, Effective Price resolution + one-active-line invariant, per-platform Deal Price export, Margin Calculator, campaign expiry reminder; Issues #73–#77) shipped. Phase 6 (Accounting, ADR 0007 + **ADR 0020**; Issues #61–#72) shipped. Phase 9 (Channel Upload, ADR 0019; Issues #46–#60) shipped; only ops #48 (R2 bucket, prod-only) remains.
- **`ref doc/`** — each platform's **real** export/template files (order, return, product, accounting), local-only. With the platforms' bulk-upload / open-API docs, this is the **authoritative column schema** for any importer or the Channel-Upload-Template fill engine — **read the actual file/doc before building or changing one; never infer column shapes from memory.**

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

## Execution model (orchestrator–worker — default for substantial work)

For any **substantial / multi-slice** work (a feature, a phase, a refactor — not a trivial edit or a conversational turn), work in an **orchestrator–worker** pattern, routing each slice to the cheapest model that fits — proven to cut cost ~40–90% at equal quality, and the way Anthropic's own multi-agent system runs.

- **Orchestrator = this main session** — owns the plan, decomposition, integration, review, and the consistency sweep. Best run on **Fable** (`/model fable`) for hard work; Opus otherwise. A smart planner writes crisp, well-specified slices, which is what lets cheap workers succeed.
- **Delegate implementation to subagents** (`.claude/agents/`), routed by slice risk:
  - **`impl-worker` (Sonnet)** — deterministic slices: importers/exporters, fill engine, coverage, CRUD, Filament/Livewire, non-money UI.
  - **`impl-critical` (Opus)** — correctness-critical: money (satang↔baht), stock ledger/movements/oversell/bundles, tenancy/RLS, payments, reconciliation.
  - **`format-worker` (Haiku)** — mechanical: formatting, renames, boilerplate, doc sweeps.
- **Keep the planner strong** — never blanket-set `CLAUDE_CODE_SUBAGENT_MODEL` (it downgrades the planner too → compounding errors). Route per slice instead.
- Each worker follows the full process (CONVENTIONS + TDD + search-before-write + the money/stock/security checks); the **orchestrator verifies and owns commit + the consistency sweep**.
- **Parallel waves (timing levers — measured 2026-06-13):**
  - Independent slices run **in parallel** — each worker gets its own test DB via `DB_DATABASE=all_ecom_test_wN php artisan test --filter=...` (w1–w4 exist on :5434; phpunit env does not force-override). Parallel workers in one checkout must create **new files only** — the orchestrator wires shared touchpoints (enums/registries/Filament tables) afterward. Sequential is still right when slices edit the same files.
  - **Gates run once**: workers run only their slice's filtered tests (+ Pint on touched files); the orchestrator runs the single authoritative full pass (Pint · Larastan max · full Pest) before each commit.
  - The orchestrator pre-reads shared schemas/patterns (e.g. a `ref doc/` file's real layout, a sibling slice to mirror) and pastes them into the worker prompt — measured ~2× faster than letting each worker re-derive.
  - For slices touching uploads/RBAC/tenancy, embed the relevant `all-ecom-security-check` items in the spec up front (avoids a rework round).

This is a working-style convention (read every session), not a hook — the one knob the human sets is the main-session model.
