---
name: all-ecom-search-before-write
description: Use before creating any new Action, Model, Livewire component, Filament Resource, Blade/Alpine component, helper, enum, Form Request, Policy, Job, or migration anywhere in the repo. Search the codebase first; reuse or extend what exists, or create it in the canonical location. Run the search even when the user explicitly says "add", "create", "build", or "write a new" construct — search first, then reuse or place canonically.
---

# All-Ecom Search-Before-Write

Reuse beats re-create — it keeps the codebase to the single convention that lets the AI (and the
solo dev) stay oriented. Before writing a new construct, **search**.

## Procedure

1. **Search first** (Grep/Glob) for an existing Action / Model / component / enum / Job that already does this or part of it. Prefer **reuse or extend** over a parallel duplicate.
2. **Place new code in the canonical location** (`CONVENTIONS.md`): Actions `app/Actions`, Requests `app/Http/Requests`, Policies `app/Policies`, Filament `app/Filament`, Livewire `app/Livewire`, Jobs `app/Jobs`.
3. **Match the existing idiom** — naming, signature shape, the surrounding pattern. Do not invent a second way to do a thing the repo already does one way (Action pattern, Filament Resource, `BelongsToTenant`, the central import pipeline).
4. **Reuse the domain vocabulary.** A concept named in `CONTEXT.md` already has a canonical term — use it; do not coin a synonym the glossary's `_Avoid_` list rejects (e.g. don't add "warehouse" where the term is **Location**, or "kit table" where a **Bundle** is a Variant).
5. **Reuse the mechanism, don't fork it.** A new gated action **registers a Permission** in the existing catalogue (ADR 0012) — it does not build a new gate. A new Excel import uses the **central bulk pipeline** (Phase 0) — it does not write a one-off parser.

If after searching nothing fits, create it once, in the canonical place, in the canonical style — so the *next* search finds it.
