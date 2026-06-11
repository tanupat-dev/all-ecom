---
name: all-ecom-local-server-hosting
description: Use before starting the local Laravel dev server / any long-running background process (php artisan serve · queue:work · npm run dev / vite · anything that binds a port and does not exit on its own), and whenever a task needs a live HTTP / Filament / Livewire instance or a real browser to drive. ALSO use at the end of any turn in which you started such a server — you own stopping it. NOT for one-shot commands that self-terminate (artisan migrate/test/tinker, composer, a Pest run, curl). For most verification prefer in-process Pest (no server) → all-ecom-verify; host only when you need a real running instance.
---

# All-Ecom Local-Server Hosting

## Core principle

**You host the port in-tool, and you stop it when the task that needed it is done.**
Started it → own stopping it. **No orphan servers, no orphan shells** across turns or sessions.

Before hosting at all, ask: *do I actually need a live server?* For route/DB/Livewire checks the answer
is usually **no** — `php artisan test` (Pest) boots the app **in-process**, needs no port, and leaves
nothing to clean up (see all-ecom-verify). Host a live server only for a **visual check** or a
**real-browser e2e** (the POS Alpine cart, a Filament screen).

## Environment

**Dev runs inside WSL2 Ubuntu** (the project lives at `~/projects/all-ecom`, prod-parity with the Linux
Hetzner box; edited via VS Code Remote-WSL). Run a Claude Code dev session **from the WSL path** so the
Bash tool is native Linux. Commands below are Linux.

## Hosting pattern

1. **Launch via `run_in_background`** (NOT foreground `&`, which the harness reaps):
   - app: `php artisan serve --port=8000` (from repo root).
   - assets (if testing compiled JS/Alpine): `npm run dev` (Vite) in a second bg task.
   - queue (if the flow needs a Job to run — bulk import, etc.): `php artisan queue:work --stop-when-empty`
     (self-terminates; prefer it over a perpetual `queue:work` you must stop).
2. **Wait for readiness with polling, never `sleep`** — use curl's own retry (readiness-probe best
   practice): `curl -s -o /dev/null -w "%{http_code}\n" --retry-connrefused --retry 20 --retry-delay 2 --max-time 5 http://localhost:8000/up`
   Laravel ships a `/up` health route; confirm `200` before driving anything.
3. **Confirm up in a separate call** before the dependent work.

## Stop — and VERIFY (port alone is a false clean)

Stop the background task you started, then **verify** — don't trust the stop message; a stop can report
success while a wrapper tree survives.
```
# 1) stop the bg task you launched (by its id)
# 2) verify the PORT is free:
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/up      # → 000 (refused)
# 3) verify NO process tree survives (the real check):
ps -eo pid,args | grep -E 'artisan serve|vite|queue:work' | grep -v grep   # → NONE
```
If survivors remain, kill by **explicit PID** (child→parent), never `pkill -f` (it self-matches its own
command line) and never `killall`:
```
lsof -ti:8000        # the bound listener PID
kill <pid> ...       # explicit PIDs
```
`php artisan serve` is a single process (easy); a Vite/`npm` run spawns a `node` tree — check the tree,
not just the port.

## Cleanup discipline (the reason this skill exists)

- **Stop every server you started** at the end of the task/turn that needed it — unless the user said to
  leave it up.
- If you intentionally leave one up, **SAY SO in your closing summary** (`app still up on :8000 for
  <reason>; stop with <how>`). Never silently abandon it. "Task isn't fully done" is NOT a reason to
  orphan it — stop it when the step needing it is done; relaunching is cheap.
- A server you started is YOURS — the next turn does not clean up after you.

## Reading server logs

Read the hosted server's output yourself in the main loop (`Read` the bg task's output file, or
`tail storage/logs/laravel.log`). Do NOT dispatch a sub-agent to watch/judge logs.

## Red flags — STOP

- Ending a turn with a server you started still alive → stop it (or declare it).
- Reported "stopped" after only freeing the port → the Vite tree may still be alive; check `ps`. **Port-down ≠ tree-dead.**
- `pkill -f` / `killall` to stop → stop the bg task by id, then kill survivors by explicit PID.
- Foreground `&` or `sleep` to host/wait → `run_in_background` + `curl --retry-connrefused`.
- Hosting a server just to check a route/DB/Livewire result → use in-process Pest (all-ecom-verify).
