---
name: all-ecom-triage
description: The issue state machine for tanupat-dev/all-ecom and the source of "what's left to do". Use to label a new Issue, move an Issue's state, or answer "what's next / what's left". Every Issue carries exactly one category (bug | enhancement) and one state (needs-triage | needs-info | ready-for-agent | ready-for-human | wontfix). ready-for-agent = AFK loop-runnable; ready-for-human = HITL needs Tan. The open ready-for-agent list is the build queue; needs-triage / needs-info need attention.
---

# All-Ecom Triage

GitHub Issues are the **durable backlog and checklist**. This skill is the label state machine that
keeps "what's left, and where each item stands" answerable at a glance.

## Labels (create once — the `setup` step)

**Category (exactly one):**
- `bug` — something is broken.
- `enhancement` — new feature / improvement.

**State (exactly one):**
- `needs-triage` — not yet evaluated.
- `needs-info` — waiting on Tan for clarification.
- `ready-for-agent` — fully specified by ADR/ROADMAP; an agent can build it end-to-end (**AFK**, loop-runnable).
- `ready-for-human` — needs Tan: a design decision, a UX choice, or a money/stock rule not yet documented (**HITL**).
- `wontfix` — rejected / out of scope.

## Flow

```
new → needs-triage → needs-info ⇄ → ready-for-agent | ready-for-human | wontfix
```
Record each transition in an Issue comment (so a later session resumes without re-litigating a
resolved question).

## "What's left / what's next" queries

- **Build queue (loop):** open + `ready-for-agent`, in dependency order → pull the top unblocked one.
- **Needs Tan:** open + `ready-for-human` → the HITL decisions to make next.
- **Needs attention:** `needs-triage` (classify) + `needs-info` (answer).
- A `ready-for-human` Issue becomes `ready-for-agent` once its decision is made + documented (ADR/CONTEXT).

`ready-for-agent` / `ready-for-human` are the Issue-level mirror of the **[AFK]/[HITL]** slice tags in
`all-ecom-engineering-process`. Requires `gh` (or the GitHub UI) to apply labels.
