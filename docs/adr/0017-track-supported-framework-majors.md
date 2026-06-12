# Build on the current security-supported framework majors: Laravel 13 + Filament 5 + Livewire 4 (refines the Phase-0 stack lock)

The Phase-0 stack lock ("Laravel 11 + Filament + Livewire + Alpine + PostgreSQL") named the major
versions current at decision time. At first-scaffold time (June 2026), **Laravel 11 is past its
security end-of-life** (security fixes ended 2026-03-12) and carries unpatched security advisories —
Composer refuses to install it by default. This ADR refines the stack lock: the **framework choices
stay locked** (Laravel + Filament + Livewire + Alpine + PostgreSQL), but the project builds on the
**newest majors inside their security-support window**, which today means:

- **Laravel 13** (released 2026-03-17 · PHP ≥ 8.3 · security fixes until Q1 2028 · zero breaking
  changes from 12)
- **Filament 5** (`filament/filament ^5.0`, supports `illuminate ^11.28|^12.0|^13.0`)
- **Livewire 4** (required by Filament 5; Alpine bundled)

Maintenance policy going forward: when a major used here approaches security EOL, upgrading to the
next supported major is **routine maintenance, not a stack change** — no new ADR needed. Swapping a
framework itself (e.g. Filament → something else) still requires a superseding ADR.

## Why

- **Shipping an EOL framework on a multi-tenant SaaS that holds money and customer PII is
  indefensible.** Security fixes for Laravel 11 ended 2026-03-12; known advisories
  (`PKSA-mdq4-51ck-6kdq` et al.) will never be patched on the 11.x line. The ROADMAP's own rule
  permits revisiting a locked decision when it "genuinely conflicts with an industry standard" —
  this is that case.
- **Greenfield = the upgrade is free.** Phase 0 has zero application code; choosing 13 now costs
  nothing, while choosing 11/12 schedules a forced framework upgrade mid-build (12's security window
  closes 2027-02-24, well inside the build horizon).
- **The conventions the stack was locked for are unchanged.** Laravel 13 keeps the same structure
  (Actions/Form Request/Policy/Job patterns are project conventions, not framework features);
  Laravel 12→13 was released with zero breaking changes; Filament keeps the Resource pattern. The
  *reason* for the lock — two layers of high convention so an AI can't build a messy structure —
  carries over intact.

## Considered options

- **Install Laravel 11 anyway (force past the Composer advisory block).** Rejected outright:
  permanently unpatched security advisories on day one, for a product whose ADR 0011/0016 invest
  heavily in defense-in-depth. Forcing the install would contradict the project's own security
  posture.
- **Laravel 12** (still in support). Safe today, but its security window ends 2027-02-24 — a forced
  mid-build major upgrade for no benefit, since 12→13 is a zero-breaking-change step we can take for
  free now.
- **Laravel 13 + Filament 5 + Livewire 4 (chosen).** Longest security runway (Q1 2028), PHP 8.3
  requirement already met by the dev/CI runtime, all locked frameworks compatible.

## Consequences

- `CONVENTIONS.md`, `CLAUDE.md`, and `docs/ROADMAP.md` Phase 0 now read "Laravel 13 · Filament 5 ·
  Livewire 4" (swept in the same commit as this ADR).
- Issue #2 (scaffold) is built on `laravel/laravel ^13.0`.
- Framework majors are upgraded as routine maintenance before their security EOL; only a change of
  framework (not of version) requires a superseding ADR.
