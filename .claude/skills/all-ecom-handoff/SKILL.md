---
name: all-ecom-handoff
description: Use at the end of a session, or when the user says "handoff" / "what's next" / "continue next time", to compact the current conversation into a portable handoff document saved to the OS temp directory for the next agent. It summarizes what happened, references existing artifacts (ROADMAP, ADRs, open Issues by #, recent commits) by path/URL instead of repeating them, lists the skills the next agent should use, and states the next focus. Redacts secrets/PII. The durable "what's left" backlog lives in GitHub Issues (see all-ecom-triage), NOT in this doc. Takes an optional argument describing the next session's focus.
---

# All-Ecom Handoff

Produce a portable, **ephemeral** handoff that lets the next agent continue without re-reading the
whole history. It is the narrative bridge between sessions — **not** the backlog. The durable list of
remaining work lives in **GitHub Issues** (`all-ecom-triage`); this doc points at it.

## Output location

Save the handoff to the **OS temp directory** (Windows: `$env:TEMP`), e.g.
`$env:TEMP\all-ecom-handoff-<yyyymmdd-hhmm>.md`. **Do not** commit it to the repo — it's ephemeral.
Print the path when done.

## Procedure

1. **Synthesize** a concise summary of what this session did and decided.
2. **Identify the artifacts** it produced or touched — ADRs (by id), `docs/ROADMAP.md` sections,
   `CONTEXT.md` terms, **open Issues by #**, recent commits (by hash), any diff in flight.
3. **Reference, don't repeat** — link each artifact by path / URL / issue# / commit hash. The handoff
   carries pointers and rationale, not copies.
4. **Strip sensitive data** — no secrets, tokens, or buyer PII; reference `ref doc/` by name only.
5. **Suggested skills** — list the skills the next agent should invoke (e.g. `all-ecom-handoff` to
   re-orient, `all-ecom-engineering-process` + `all-ecom-tdd` to build the next slice,
   `all-ecom-business-rules-check` before money/stock).
6. **Next focus** — the single concrete next action; tailor to the optional argument if given. Point at
   the relevant **`ready-for-agent`** Issue(s) (the loop queue) or the **`ready-for-human`** decision blocking it.

## What this skill is not

- Not the backlog — that's GitHub Issues + triage labels (`ready-for-agent` / `ready-for-human`).
- Not version-controlled — it lives in temp and is regenerated each session.
- Not a place for secrets or raw `ref doc/` data.
