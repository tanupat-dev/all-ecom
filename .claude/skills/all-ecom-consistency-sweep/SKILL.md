---
name: all-ecom-consistency-sweep
description: Use as part of EVERY commit that changes behaviour, a name, a signature, a domain term, a config value, or a decision — sweep the whole codebase for the docs, comments, tests, and code references the change makes stale or dead, and fix or delete them in the SAME commit. Keeps CONTEXT.md / ADRs / ROADMAP / CONVENTIONS / code comments / tests in sync so neither an agent nor a person ever reads an outdated architecture. NOT needed for a purely additive change that invalidates nothing existing.
---

# All-Ecom Consistency Sweep

**Stale docs and comments are a liability, not just untidy** — an AI agent reads an outdated
architecture and confidently re-introduces a pattern you removed, or cites a rule that no longer
holds. Treat docs as code: when the code moves, every reference to its old shape moves **in the same
commit**. (This is the codebase analogue of the one-source-of-truth memory discipline.)

## On every behaviour/name/term/decision change, sweep & fix in the same commit

1. **Grep for every reference to the old shape** — the renamed symbol, the changed signature, the old
   term, the removed route/permission/config — across **code, comments, tests, and docs**. Leave
   nothing pointing at what no longer exists.
2. **`CONTEXT.md`** — if a term's meaning/fields changed or a new domain concept appeared, update the
   term (it is the glossary of record; keep it implementation-free).
3. **ADRs** — if a *decision* changed, **write a new ADR that supersedes the old** and mark the old
   `Status: superseded by ADR-NNNN` (as 0008→0012, 0004 amended). Never silently edit a past decision.
4. **`ROADMAP.md` / `CONVENTIONS.md`** — update if scope, phase order, or a convention shifted.
5. **Code comments / docblocks** — update any that now lie; **delete dead comments**. A comment should
   *reference the canonical rule* (CONTEXT/ADR), not restate logic that will drift.
6. **Tests** — update assertions that encoded the old behaviour; delete tests for deleted code.
7. **Dead code** — after grepping for references, remove now-unreferenced code, imports, routes,
   Permissions, or migrations. Nothing orphaned.

## Verify nothing stale remains

After the sweep, **grep once more** for the old name/term — zero hits outside the changelog/superseded
ADR. A reference that survives is a future hallucination source.

## Fits the loop

In a self-correcting (Ralph-style) loop this sweep is a **validated step each iteration runs**: a
stale-reference grep that still hits should *fail* the iteration and feed back, so drift never
accumulates across autonomous commits. Optionally enforce a known-removed-symbol grep as a pre-commit
check.

## Anti-patterns

- Committing a rename/behaviour change and "updating the docs later" — later never comes; the agent
  reads the stale doc next turn.
- Editing a past ADR in place to reflect a new decision — write a superseding ADR instead.
- A comment that restates the logic (drifts) instead of referencing the rule (CONTEXT/ADR).
- Leaving a dead route/Permission/import because "it's harmless" — it's a false signal the next search trusts.
