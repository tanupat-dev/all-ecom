---
name: all-ecom-local-server-hosting
description: Use before starting the local Laravel dev server / any long-running background process in this repo (php artisan serve · sail up · queue:work · npm run dev / vite · anything that binds a port and does not exit on its own), and whenever a task needs a live HTTP / Filament / Livewire instance or a real browser to drive. ALSO use at the end of any turn in which you started such a server — you own stopping it. NOT for one-shot commands that self-terminate (artisan migrate/test/tinker, composer, a Pest run, curl). For most verification prefer in-process Pest (no server) → all-ecom-verify; host only when you need a real running instance.
---

# All-Ecom Local-Server Hosting

## Core principle

**You host the port in-tool, and you stop it when the task that needed it is done.**
Started it → own stopping it. **No orphan servers, no orphan shells** across turns or sessions.

Before hosting at all, ask: *do I actually need a live server?* For route/DB/Livewire checks the
answer is usually **no** — `php artisan test` (Pest) boots the app **in-process**, needs no port,
and leaves nothing to clean up (see all-ecom-verify). Host a live server only for a **visual check**
or a **real-browser e2e** (the POS Alpine cart, a Filament screen).

## Hosting pattern

1. **Launch via `run_in_background`** (PowerShell tool, native Windows — NOT a foreground call that blocks):
   - app: `php artisan serve --port=8000` (from repo root).
   - assets (if testing compiled JS/Alpine): `npm run dev` (Vite) in a second bg task.
   - queue (if the flow needs a Job to run — bulk import, etc.): `php artisan queue:work --stop-when-empty`
     (this one self-terminates; prefer it over a perpetual `queue:work` you must stop).
2. **Wait for readiness with polling, never `sleep`** (foreground `sleep` is blocked anyway).
   Use curl's own retry — readiness-probe best practice, not a fixed wait:
   `curl -s -o /dev/null -w "%{http_code}\n" --retry-connrefused --retry 20 --retry-delay 2 --max-time 5 http://localhost:8000/up`
   Laravel ships a `/up` health route; confirm `200` before driving anything. (`artisan serve` boot is
   fast, ~1–2s; Vite first build is slower — poll it too if the test needs compiled assets.)
3. **Confirm up in a separate call** before the dependent work.

## Stop — and VERIFY (port alone is a false clean)

Stop the background task you started (the id `run_in_background` returned), then **verify** — do
**not** trust the stop message; a stop can report success while a wrapper tree survives. **Dev runs on
native Windows**, so the verify/kill commands are PowerShell (`netstat`/`taskkill`/`Get-Process`):

```powershell
# 1) stop the bg task you launched (by its id)
# 2) verify the PORT is free:
(Invoke-WebRequest http://localhost:8000/up -TimeoutSec 3 -ErrorAction SilentlyContinue).StatusCode  # → empty/err (refused)
#    or: curl.exe -s -o NUL -w "%{http_code}" http://localhost:8000/up    # → 000
# 3) verify NO process survives (the real check):
Get-Process php, node -ErrorAction SilentlyContinue    # → none for this app
netstat -ano | Select-String ":8000"                   # → no LISTENING line
```

If survivors remain, kill by **explicit PID** — find it then `taskkill /F`:
```powershell
netstat -ano | Select-String ":8000"        # last column = PID of the listener
taskkill /PID <pid> /F
```
`php artisan serve` is a single `php` process (easy). A `npm run dev`/Vite run spawns a `node` tree —
check `node` too, not just the port. If you ever use Sail (Docker), stop with `sail down` instead.

## Cleanup discipline (the reason this skill exists)

- **Stop every server you started** at the end of the task/turn that needed it — unless the user said
  to leave it up.
- If you intentionally leave one up, **SAY SO in your closing summary** (`app still up on :8000 for
  <reason>; stop with <how>`). Never silently abandon it. "Task isn't fully done" is NOT a reason to
  orphan it — stop it when the step needing it is done; relaunching is cheap.
- A server you started is YOURS — the next turn does not clean up after you.

## Reading server logs

Read the hosted server's output yourself in the main loop (`Read` the bg task's output file, or
`tail storage/logs/laravel.log`). Do NOT dispatch a sub-agent to watch/judge logs — money/stock
signal lives there.

## Red flags — STOP

- Ending a turn with a server you started still alive → stop it (or declare it).
- Reported "stopped" after only freeing the port → the Vite/sail tree may still be alive; check `ps`.
  **Port-down ≠ tree-dead.**
- Reaching for `pkill -f` / `killall` to stop → stop the bg task by id, then kill survivors by explicit PID.
- Foreground `&` or `sleep` to host/wait → `run_in_background` + `curl --retry-connrefused`.
- Hosting a server just to check a route/DB/Livewire result → use in-process Pest instead (all-ecom-verify).

## Environment

**Dev runs on native Windows** (not WSL/Ubuntu). PHP comes from **Laravel Herd (Windows)** or a native
PHP install; commands are run through the PowerShell tool. Prod is Linux (Hetzner) — that gap is normal;
keep dev/prod parity via tests + CI, not by matching shells. The one Phase-0 choice still open is *how PHP
runs on Windows* (Herd native = simplest, vs Sail/Docker Desktop = closer to the Linux prod box); lock it in
`CONVENTIONS.md`. The host/verify lifecycle here is the same either way — only `sail down` replaces the
`taskkill` path if you go the Docker route.
