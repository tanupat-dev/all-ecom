---
name: all-ecom-handoff
description: Use at the START of a session to orient (read STATUS.md — where we are + what's next), and at the END of a session or whenever the user says "handoff" / "what's next" / "continue next time" / "what's left" — update STATUS.md (what got done, the next action, refresh the AFK/HITL checklist, list pending HITL decisions) and commit it. STATUS.md is the in-repo living handoff + checklist. NOT for a one-off question answerable from ROADMAP/ADR without changing the live state.
---

# All-Ecom Handoff

`STATUS.md` (repo root) is the **living handoff + checklist** — the single answer to "where are we,
what do I do next, what's left." It is **in-repo and committed** (a deliberate change from the upstream
handoff that writes to a temp dir): the checklist and the "next session starts here" must persist, be
version-controlled, and be visible to Tan, not vanish with the conversation.

## Division of artifacts — don't duplicate

- `docs/ROADMAP.md` = the plan (phases). Stable.
- `docs/adr/` = the decisions. Append-only.
- `CONTEXT.md` / `CONVENTIONS.md` = the rules.
- **`STATUS.md` = the live pointer** — current position, the immediate next action, the remaining
  checklist (AFK/HITL), open HITL decisions, links to recent commits. **Reference** the others by
  path/id; never restate their content here. Keep it short.

## Session start

**Read `STATUS.md` first.** "▶️ Next session: start here" is the entry point. Don't re-derive state
from the whole history — STATUS.md is the summary.

## Session end / on demand ("handoff" / "what's next" / "what's left")

Update `STATUS.md` and **commit it**:
1. **You are here** — move completed items out; reflect the new current position.
2. **Next session: start here** — the single concrete next action (and what gates it).
3. **Backlog checklist** — tick `[x]` done, add new slices, keep each tagged **[AFK]** (loop-runnable)
   or **[HITL]** (needs a Tan decision — name the decision).
4. **Open decisions (HITL)** — list anything parked/awaiting Tan; remove resolved ones.
5. **References** — update the last-commits line and any new ADR/doc ids.
6. Bump the `_Last updated_` line. **Redact** any secret/PII (reference `ref doc/` by name, never paste it).
7. Commit (`docs: update STATUS handoff`) — and run `all-ecom-consistency-sweep` if the change also
   touched docs/decisions elsewhere.

## Graduating to GitHub Issues

When slices need **parallel or autonomous-loop execution**, decompose the backlog into GitHub Issues
(upstream `to-issues`) with triage labels **`ready-for-agent`** (= AFK) / **`ready-for-human`** (= HITL).
STATUS.md then keeps the narrative ("you are here / next") and **links to the issue list** as the
granular checklist. Until then, the STATUS.md checklist is enough — don't stand up a tracker before the
work justifies it.

## Anti-patterns

- Restating ROADMAP/ADR content in STATUS.md — it drifts; link instead.
- Letting STATUS.md grow into a log — it's a *live pointer*, not history (history is git + memory).
- Ending a working session without updating it — the next session then re-derives state from scratch.
- Pasting a secret, token, or buyer PII into it.
