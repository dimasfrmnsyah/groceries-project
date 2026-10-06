# Absensi Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Record cashier shifts and give administrators detailed attendance and monthly overtime reports without changing sales or revenue/logout behavior.

**Architecture:** A dedicated attendance table, model, and controller provide authenticated check-in/check-out and scoped reports. A small header partial calls these endpoints; the existing database-backed menu grants the report to admin/superadmin.

**Tech Stack:** Laravel 10, Blade, Bootstrap, native browser fetch, PHPUnit with isolated SQLite.

**Spec:** User-approved conversation design, including the final correction: shifts stay active across midnight until manually checked out.

## Global Constraints

- Eligible roles: staff, kasir, cashier. Reports: admin/superadmin, constrained by existing store access.
- Normal time is up to 8 hours per shift; excess is overtime, without automatic break deduction or rounding to whole hours.
- Never close shifts automatically; login/logout does not start or end attendance.
- Store timestamps in UTC, display in Asia/Jakarta (WIB); report month uses check-in date in WIB.
- Open shifts show Belum absen keluar and do not contribute finalized hours/overtime.
- No unrelated refactoring or dependency additions.

## Review Focus

- Repeated/stale requests must not create or close a different shift.
- Another user's attendance and another store's reports must remain inaccessible.
- Midnight/month transitions must preserve exact elapsed time and WIB month boundaries.
- Open shifts, short shifts, and exactly eight hours must have accurate descriptions and totals.
- Header failures must leave checkout, sales, and revenue logout functional.

## Tasks

- [x] Write feature tests using an isolated database for roles, duplicate requests, stale shift IDs, durations, and monthly store scope. Run and confirm missing feature failures.
- [x] Add attendance migration/model/controller with explicit endpoints, server-side time, row locking, unique active user and check-in request key, and persisted finalized seconds.
- [x] Add report filters, monthly per-employee aggregates, paginated detail rows, and menu migration; test report scope and month boundaries.
- [x] Integrate header partial with disabled in-flight actions, status refresh, explicit check-out confirmation, and completed-shift summary. Preserve the surrounding layout and logout scripts.
- [x] Run attendance tests, regression suite, Blade compilation, route discovery, and review the diff. Document deployment migration and test limitations.

## Verification and deployment

- Feature and regression tests run against isolated SQLite, never against shop data.
- The admin report was verified in the existing Chrome session at http://localhost:8000/attendance after the missing view was added.
- Database migration status confirms both attendance migrations already ran in the configured database. On another deployment, apply the two 2026_10_06 attendance migrations before using this feature.
- Cashier header behavior is covered by JavaScript tests, including check-out confirmation, duplicate-click suppression, lost-network retry identity, and expired sessions. No real employee attendance was created for browser testing.
- Employee options include current eligible staff in accessible stores even before their first shift, plus historical employees.
- Concurrent locking uses the user row plus a unique active-user index; true concurrent MySQL requests were not load-tested.
