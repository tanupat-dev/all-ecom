---
name: all-ecom-to-issues
description: Use to turn a plan — a ROADMAP phase, a spec, or this conversation — into GitHub Issues on tanupat-dev/all-ecom. Decomposes the work into thin vertical slices (tracer bullets), each demoable/verifiable on its own, tagged ready-for-agent (AFK, loop-runnable) or ready-for-human (HITL, needs Tan), each with acceptance criteria referencing CONTEXT/ADR, published in dependency order with blockers linked. Run before building a phase so there is a backlog to pull from. Requires the repo's triage labels (all-ecom-triage). NOT for a single trivial change — just do it.
---

# All-Ecom → Issues

Convert a plan into an actionable, dependency-ordered set of GitHub Issues — the durable backlog the
build (or an autonomous loop) pulls from.

## Procedure

1. **Gather context** — the ROADMAP phase / conversation / referenced issues. Apply `CONTEXT.md`
   vocabulary and respect the ADRs throughout.
2. **Draft vertical slices** — break the work into **thin, end-to-end** slices (migration → Action →
   Policy → Filament/Livewire → test), each **demoable or verifiable on its own**. Prefer **many thin
   over a few thick**; never a horizontal layer-at-a-time issue. For each slice:
   - **Title** — outcome-focused.
   - **Body** — user story / acceptance criteria, **referencing the ADR + CONTEXT terms** that pin it (link, don't restate).
   - **Blockers** — the issue #s it depends on.
   - **Category label** — `enhancement` or `bug`.
   - **State label** — `ready-for-agent` if fully specified by ADR/ROADMAP (no open decision); else `ready-for-human` (names the decision needed).
3. **Quiz Tan** — present the proposed slices (title · state · blockers · acceptance) and iterate on
   granularity, dependencies, and AFK/HITL classification **before** publishing.
4. **Publish** — create the Issues in **dependency order** (`gh issue create` with title/body/labels),
   linking blockers. The set of open **`ready-for-agent`** Issues is the loop queue; **`ready-for-human`**
   ones wait on Tan.

## Notes

- A slice is AFK (`ready-for-agent`) only when every decision it needs is already in an ADR/CONTEXT.
  If a money/stock rule is missing → it's HITL; resolve via `all-ecom-standard-first` /
  `all-ecom-business-rules-check` and add the ADR first, *then* the slice becomes AFK.
- Requires `gh` authenticated as `tanupat-dev` and the triage labels created once (`all-ecom-triage`).
- Build each published slice with `all-ecom-engineering-process` + `all-ecom-tdd`.
