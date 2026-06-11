---
name: all-ecom-security-check
description: Use before committing any code that touches tenant scoping / `tenant_id` / RLS, authentication or sessions, Roles & Permissions, Payments or refunds, customer PII, file uploads (Excel import), raw SQL, or secrets/config. Runs a security checklist against the change. Highest blast radius in this codebase — a missing tenant scope leaks one seller's data to another. Do NOT use for pure UI/content, design tokens, or a non-sensitive refactor that touches none of the above.
---

# All-Ecom Security Check

Run this checklist before committing anything in the sensitive set. Multi-tenancy makes data
isolation the top risk: this is a SaaS where one bug exposes one seller's catalog, money, or
customers to another.

## Checklist

**1. Tenant isolation (the #1 risk — ADR 0011).**
- Every new model `use BelongsToTenant`; every query is tenant-scoped (global scope on, never a raw unscoped `DB::table()` that bypasses it).
- The table has an **RLS policy**; the app connects as a **non-owner** DB role so RLS actually applies.
- Add/extend the **cross-tenant isolation test** — assert a User of Tenant A cannot read/modify Tenant B's row. No new sensitive table ships without it.

**2. RBAC (ADR 0012).**
- Each gated action checks a **named Permission via a Policy** — never `if ($user->role === 'admin')`.
- view vs edit Permissions respected; the lock-out safeguard (last `user.manage`+`role.manage`) can't be bypassed.

**3. Input & Excel upload.**
- Validate via **Form Request**; bound upload size/type; the importer **streams** (no whole-file-in-memory), and is **fail-loud** on unmapped values (ADR 0005) — never eval or trust sheet content.

**4. Raw SQL.** Parameterised only. Never string-interpolate user/tenant input into SQL.

**5. Secrets.** None in code or repo — `.env` only; scan the diff for keys/tokens (GitLeaks-style) before commit; never log a secret.

**6. PII.** Buyer name/phone/address minimised, access-gated, never written to logs.

**7. Money.** Amounts are integer **satang**; refund/void are **admin-gated** and **audit-logged** (who approved).

A change that touches any row above is not done until its line here is green — especially the tenant-isolation test.
