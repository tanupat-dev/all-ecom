---
name: all-ecom-tdd
description: Use when implementing a behaviour-bearing vertical slice — an Action, a route, a Livewire/Filament interaction, stock or accounting logic. Drives it red → green → refactor, ONE behaviour at a time (vertical tracer bullets), testing behaviour through public interfaces (Pest) — not implementation details — so tests read like a spec and survive refactors. NOT for a throwaway spike (that's a prototype), pure config, or a mechanical rename.
---

# All-Ecom TDD

Build each slice with the red-green-refactor loop. **Test behaviour through the public interface**
(an Action's result, a route's response, the DB effect, a Livewire component's rendered state) —
**not** internals. Good tests describe *what the system does* like a spec and stay green through a
refactor; tests coupled to private implementation break on every refactor for no reason.

## Plan (before the first test)

- Confirm the **public interface** (the Action signature / route / component API) and the **behaviours**
  to verify — a list of *behaviours*, not implementation steps.
- Surface the edge cases up front: money (satang, rounding), stock (negative Available, bundle
  expansion, the movement-appended-**and**-balance-updated invariant), tenancy (cross-tenant isolation),
  fail-loud import. If a behaviour isn't pinned by CONTEXT/ADR → that's HITL (`all-ecom-business-rules-check`).

## The loop

1. **Tracer bullet** — write ONE test for the first behaviour → **RED**. Implement the minimum to pass → **GREEN**.
2. **Incremental** — add ONE test per remaining behaviour; write only enough code to pass the current
   test; **don't anticipate** future tests.
3. **Refactor** — extract duplication, deepen the Action's design, apply SOLID; **run tests after each
   step**. Behaviour unchanged → tests stay green.

**Anti-pattern: horizontal slicing** — writing all tests first then all code, or all code then all
tests. It produces tests based on *imagined* behaviour. One test-implementation pair per cycle.

## Pest / Laravel specifics

- The default test is an **in-process Feature test** — it exercises the real code path + DB (`RefreshDatabase`),
  which is the "integration-style" test that gives real confidence. Pick the right layer with `all-ecom-verify`
  (Unit for pure math · Feature for routes/Actions/DB · `Livewire::test()` for components · browser only for the POS Alpine cart).
- **Assert behaviour, not internals:** assert the route status/JSON, the rendered text (`assertSee`), the
  DB state (a Stock Movement row appended **and** the denormalized balance updated), the thrown
  fail-loud error — never a private method call count.
- **One `expect`/assert to one fact** — a row of small assertions localises a regression.
- **Tenancy:** run the test as a User of a Tenant; where the slice touches tenant-scoped data, include
  the behaviour "Tenant A cannot see Tenant B's row" (ADR 0011).
- **Money/stock:** assert the **invariant and atomicity** (append + balance in one transaction; bundle
  expands to component movements; cash refund hits the Shift) — the rule from `all-ecom-business-rules-check`.

## Anti-patterns

- Testing a private method or asserting it was called — couples the test to implementation; refactor breaks it.
- A fixed `sleep` in a browser test instead of waiting on the element that changes.
- Writing the whole Action then bolting tests on after — you'll test what you built, not what was specified.
- Skipping the refactor step — green-without-refactor accretes duplication the next slice trips on.
