---
name: all-ecom-verify
description: Use to verify a change actually works — after adding/changing a route, an Action, a Filament Resource, a Livewire component, a Job, or stock/accounting logic. Picks the cheapest test layer that proves the change: in-process Pest (feature/unit) first, Livewire::test() for reactive components, and a real browser (Pest v4 / Playwright) ONLY for interactive flows that need JavaScript (the POS Alpine cart). Do NOT host a live server for checks the in-process layers cover (faster, no port, no orphan). For when you genuinely must host one → all-ecom-local-server-hosting.
---

# All-Ecom Verify

Prove a change with the **cheapest layer that actually exercises it**. Climb the pyramid only when
the layer below genuinely cannot see the behaviour. Most verification needs **no running server** —
Laravel boots the app in-process.

## The layers (cheapest first)

1. **Unit (Pest, no app boot)** — pure logic with no DB/framework: money math (สตางค์ integers),
   the `Available = On-Hand − Reserved − Buffer` formula, a Bundle's `min(floor(component/qty))`,
   SKU-resolution function rules. Fastest; use for the domain math in Actions.

2. **Feature / HTTP (Pest, in-process)** — **most tests live here.** Boots the app, hits a route,
   touches the DB — *no `artisan serve`, no port*. Use for: route status/JSON, an Action's DB effects
   (a Stock Movement appended + the denormalized balance updated in the same transaction), Policy/
   Permission gates (a Cashier role is 403 on `accounting.view`), fail-loud import behaviour, and
   **cross-tenant isolation** (a User of Tenant A can never read Tenant B's rows — this suite is a MUST).
   Run via `php artisan test` (cross-platform; on native Windows use this, or `vendor\bin\pest`).

3. **Livewire component (`Livewire::test()`)** — reactive components **without a browser**: mount
   asserts (a "smoke test" that the POS checkout / a Filament page renders), `->set()` / `->call()`
   then assert state, validation rules. Covers most POS server-side logic.

4. **Browser e2e (Pest v4, Playwright) — only when JavaScript must run.** Reserve for the things the
   layers above cannot see: the **POS cart driven client-side in Alpine** (scan → line add → change/
   tender without a round-trip), a multi-step interactive Filament action. Pest v4's browser runner is
   the recommended path for new projects (over Laravel Dusk) and **owns its own server lifecycle** —
   so you usually do NOT hand-host for it. Slower and flakier; one critical flow, not coverage padding.

If a check is server-rendered HTML or JSON, stop at layer 2 — do not reach for a browser.

## Procedure

- **Run the smallest relevant suite, not the whole thing**, while iterating:
  `php artisan test --filter=<Name>` or `pest --filter=<name>`.
- **One `assert`/`expect` to one fact** — a row of small assertions localises a regression far better
  than one composite check (mirrors the seiton harness shape).
- **Database:** use `RefreshDatabase` / a transactional rollback so tests are isolated and repeatable;
  never assert against dev data that drifts.
- **Bound everything**: a browser test waits on the *element that should change*, never a fixed `sleep`
  (the #1 source of browser-test flake).
- Tenancy: every feature test runs **as a User of a Tenant**; add at least one assertion that the other
  Tenant's data is invisible.

## What a green test does / does not prove

- A passing feature test proves the route + DB wiring in-process. It does **not** prove the compiled
  Alpine/Vite bundle behaves in a real browser — that is the layer-4 job, and only for the flows that
  need it.
- A Livewire `::test()` pass proves server-side component logic, not the client-side Alpine behaviour
  layered on top — if the bug is in the browser (a cart that mis-tallies before sync), instrument the
  **browser request/response**, don't theorise about caches.

## Anti-patterns

- Hosting `php artisan serve` + curling a route that a **feature test** would check in-process — slower,
  and you now own stopping an orphan-able server for nothing.
- A browser test to assert an SSR/Blade page contains a string — a feature test + `assertSee` is faster
  and never flakes.
- A fixed `sleep` instead of waiting on the changing element.
- Skipping the **cross-tenant isolation** test — the one failure mode of row-level tenancy is a leak;
  it must be tested, not assumed (ADR 0011).
- Adding Firefox/WebKit browser projects "for coverage" — pick one engine until a real cross-browser
  bug demands more.
- Treating a green test as proof of integration when the risk is in compiled assets or a Job running
  under the real queue — for those, run the actual Job (`queue:work --stop-when-empty`) or the layer-4
  browser flow.

Cross-ref: **all-ecom-local-server-hosting** (when you must host a live instance) · `CONVENTIONS.md`
(Pest + Larastan-max gates) · ADR 0011 (tenancy — why the isolation suite is mandatory).
