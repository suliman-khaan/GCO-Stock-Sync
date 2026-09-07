# GCO Stock Sync — Progress Tracker

**Read this file first, before anything else in `development-plan/`.** It's the
handoff doc between sessions/AIs. If you're picking this project up cold, this
tells you exactly where things stand, what to verify, and what's next.

Update this file whenever you finish a task, pass/fail a gate, or discover
something the next person needs to know. Keep it current — stale status here
is worse than no status at all.

---

## Current Status

**Active phase:** Complete (All Phases 1–7 Finished)
**Phase state:** ✅ **All 7 Phases Complete.** 93 integration tests passing, client handover and deployment guides ready, release package built.

| Phase | Status |
|-------|--------|
| 1 — Feed Reconnaissance | ✅ Done — `research/FEED-NOTES.md` complete, fixtures created |
| 2 — Plugin Skeleton & Lifecycle | ✅ Done — passes `test-lifecycle.php` (17/17) |
| 3 — Supplier Connector | ✅ Done — 15 tests passing, security-reviewed |
| 4 — Sync Engine | ✅ Done — 21 tests passing (5 matcher + 16 runner) |
| 5 — Admin UI | ✅ Done — 22 tests passing (settings, logs, product meta, menu) |
| 6 — Hardening & Release | ✅ Done — 11 new tests (93/93 total suite passing) |
| 7 — Deployment & Handover | ✅ Done — client handover guide & deployment checklist complete |

---

## Status Summary

All planned phases are complete and verified.

Full test suite (93 tests) confirmed green:

```
/c/wamp64/bin/php/php8.2.29/php.exe tests/run-tests.php
→ Summary: 93 tests, 93 passed, 0 failed
```

17 lifecycle tests (Phase 2) + 15 supplier tests (Phase 3: the 16 planned
P3-TC IDs, with TC09/10/11 qty-normalisation cases consolidated into one
test method, plus one extra test added during security review for the
https-only guard below).

### Quality gate — how it actually got done

The plan calls for `/wordpress-skills:wp-plugin-review` and
`/wordpress-skills:wp-sec-review`, but neither skill was installed in the
session that built Phase 3. `/security-review` was tried as a substitute but
requires a git repo with a diff to review, and this project had no git repo
at all at that point. **A local git repo was initialized in this plugin
folder** (`git init`, initial commit `66e07c7`) specifically to unblock this
kind of tooling going forward — if you're in a session where
`wp-plugin-review`/`wp-sec-review`/`/security-review` ARE available, use
them for future phases instead of a manual read-through.

For Phase 3, review was done manually (reading `includes/suppliers/*.php`
line by line for WP coding standards + security). Findings and resolutions:

1. **Dead code** — unused `$errors` variable in `parse_rows()`. Fixed (removed).
2. **Secret in source** — `DEFAULT_FEED_URL` embeds the real client email +
   live NetSuite auth hash as a class constant, now committed to git.
   **Decision (confirmed with client-side user): keep as-is.** This is a
   single-tenant plugin for one site; the plan explicitly calls for shipping
   a default URL (task 3.5.1). **Hard constraint: this repo must never be
   pushed to a public or shared remote** while that constant holds a live
   credential. If it ever needs to be shared, rotate the hash first (email
   johnb@highlandoutdoors.co.uk) or move it out of source into a
   settings-only value.
3. **SSRF hardening** — `feed_url` will be admin-editable once Phase 5 ships
   the settings page, and was passed to `wp_remote_get()` with no scheme
   check. **Fixed:** added `is_https_url()` in
   `class-highland-outdoors.php`, used by both `is_configured()` and as a
   defensive check at the top of `fetch()`. Non-https URLs now fail fast
   with `error_code = 'fetch_failed'` before any HTTP call is made. Covered
   by `test_non_https_feed_url_rejected`. **Carry this rule into Phase 5:**
   the settings-page save handler should also reject non-https URLs at
   input time, not just rely on the connector catching it at fetch time.

---

## Phase 3 — What Was Built

Files created this phase (per `development-plan/phase-3-supplier-connector/plan.md`):

- `includes/suppliers/interface-supplier.php` — `GCO_Stock_Sync_Supplier_Interface`
- `includes/suppliers/class-fetch-result.php` — `GCO_Stock_Sync_Fetch_Result` (`::success()` / `::failure()` factories)
- `includes/suppliers/abstract-supplier.php` — settings storage helpers + debug-transient helper
- `includes/suppliers/class-highland-outdoors.php` — the actual connector
- `tests/integration/test-supplier-highland.php` — P3-TC01 through P3-TC16

Wiring:
- `includes/class-plugin.php` now loads the supplier files, registers Highland
  Outdoors via `add_filter( 'gco_stock_sync_suppliers', ... )`, and exposes
  `GCO_Stock_Sync_Plugin::get_instance()->get_suppliers()`.
- `tests/bootstrap.php` and `tests/run-tests.php` updated to load/run the new test file.

### Key implementation decisions (so you don't have to re-derive them)

1. **Section-header rule** implemented exactly as documented in
   `research/FEED-NOTES.md` §5: a row is a section header (excluded) if
   Trade Price is empty **AND** Qty is `=0`/`0`/empty. Everything else is a
   product. This means some genuinely out-of-stock SKUs with no price get
   silently excluded — FEED-NOTES.md calls this out as intentional and safe
   (see "Ambiguity" note there), because excluding them has the same net
   effect as including them at qty=0.
2. **Qty normalisation**: strip leading `=`, strip commas, cast to number,
   negative → 0, non-numeric/empty → row skipped entirely (not qty=0).
3. **Debug transient key is NOT per-supplier**: `gco_stock_sync_last_error_body`
   (no supplier suffix), matching the literal key used in
   `phase-3-supplier-connector/test-cases.md` P3-TC16. If a second supplier is
   added later and this becomes a problem (transient overwritten by whichever
   supplier failed last), that's a deliberate simplification to revisit in
   Phase 4+, not a bug.
4. **Feed URL and min-row threshold are both settings**, not constants —
   `GCO_Stock_Sync_Highland_Outdoors::DEFAULT_FEED_URL` / `DEFAULT_MIN_ROWS`
   are just the shipped defaults (option `gco_stock_sync_supplier_highland_outdoors`).
5. **Sanity guard** default minimum is 10 product rows (per FEED-NOTES.md,
   live feed currently has ~111). Fewer than that → `ok=false`,
   `error_code='suspiciously_empty'`, even if the HTTP fetch itself succeeded.
   This is the #1 safety mechanism from the master plan ("a failed connection
   must never mark products out of stock") — Phase 4's sync runner must treat
   *any* `ok=false` result as "skip this supplier's products, don't touch stock."
6. **Parsing uses `DOMDocument` + `DOMXPath`**, no regex, per plan §3.5.4.
   `libxml_use_internal_errors(true)` is set/restored around the parse.

### Known real-world risk (carried over from Phase 1)

The Highland Outdoors feed URL contains a `hash` parameter that may rotate.
If Phase 3/4 testing against the *live* feed suddenly fails with
`parse_failed` or `fetch_failed`, check `research/FEED-NOTES.md` §Auth first —
this is very likely an expired hash, not a code bug. Contact
johnb@highlandoutdoors.co.uk for a fresh URL. **Do not "fix" the parser to
work around an auth failure.**

---

## Quality Gate for Phase 3 (do this before starting Phase 4)

Per `development-plan/phase-3-supplier-connector/plan.md`:

```
/wordpress-skills:wp-plugin-review includes/suppliers
/wordpress-skills:wp-sec-review includes/suppliers
```

Plus: all 16 tests in `tests/integration/test-supplier-highland.php` passing
via `tests/run-tests.php` (or the real WP PHPUnit suite if `WP_TESTS_DIR` is set).

Tests: ✅ done (32/32 passing, see "Immediate Next Step").
Skill reviews: ✅ done manually (`wp-plugin-review`/`wp-sec-review` skills
unavailable this session; see "Immediate Next Step" for what was done instead
and the findings that came out of it).

---

## How to Resume This Project (for any AI/session)

1. Read this file top to bottom.
2. Read `development-plan/master-plan.md` for architecture, hard requirements,
   and the safety principle ("when in doubt, do nothing to stock").
3. Check the Phase Status table above — find the first non-✅ phase.
4. Read that phase's `plan.md` and `test-cases.md` in
   `development-plan/phase-N-*/`.
5. Check "Immediate Next Step" above for anything left mid-task.
6. Run the test suite before writing new code, to confirm the baseline you're
   inheriting actually works:
   ```bash
   /c/wamp64/bin/php/php8.2.29/php.exe tests/run-tests.php
   ```
7. When you finish a task or hit a gate, **update this file** — status table,
   "Immediate Next Step", and add a dated entry to the Session Log below.

---

## Session Log

- **2026-09-07** — Phase 1 & 2 completed (prior session). Phase 3 code written
  (supplier interface, fetch result, abstract supplier, Highland Outdoors
  connector, integration tests). Full suite run against local WP: 31/31
  passing. Manual security/coding-standards review done (automated
  `wp-plugin-review`/`wp-sec-review` skills unavailable); git repo
  initialized in the plugin folder to unblock this tooling going forward.
  Findings: removed dead code, added https-only guard on the feed URL
  (SSRF hardening) with a new test, decided to keep the hardcoded default
  feed URL/credential as-is per single-tenant scope (repo must stay
  private). Final suite: **32/32 passing**. Phase 3 marked complete.
- **2026-09-08** — Phase 4 completed (product matcher, sync runner, stock transition
  logic, 60/60 tests). Phase 5 completed (admin menu under WooCommerce -> Stock Sync,
  Settings API tab with status panel and AJAX manual sync, Log Page with WP_List_Table,
  retention auto-purge, run detail view, product meta box toggle in Inventory tab,
  and product list column). Added 22 integration tests covering all P5-TC01 to P5-TC22.
  Full test suite: **82/82 passing** (0 failures, 0 warnings). Phase 5 marked complete.
- **2026-09-08** — Phase 6 completed (Hardening, i18n, Failure Alerting, WP-CLI, Release).
  Added `GCO_Stock_Sync_Failure_Notifier` with consecutive failure tracking, 24-hour alert deduplication,
  and automatic reset on success. Added interactive "Test Connection" button in admin with AJAX handler.
  Added Cron schedule method selection (WP-Cron vs Server System Cron) and comprehensive in-admin
  Cron Pattern Reference & Server Setup Guide with copyable WP-CLI/curl commands. Implemented WP-CLI
  command `wp gco-stock-sync run` supporting `--dry-run`, `--supplier`, and `--force`. Added full i18n pass,
  generated `languages/gco-stock-sync.pot` (164 strings), built release script `bin/build-release.ps1`
  producing clean zip `release/gco-stock-sync-1.0.0.zip` (49 KB). Added 11 integration tests in
  `tests/integration/test-hardening.php`. Full test suite: **93/93 passing** (0 failures). Phase 6 marked complete.
- **2026-09-08** — Phase 7 completed (Deployment & Handover).
  Created comprehensive non-technical client handover guide in `docs/client-handover.md` covering
  navigation, settings, status indicators, failure alerting, product toggles, and Highland Outdoors
  feed URL escalation procedure. Created step-by-step deployment checklist in `docs/deployment-checklist.md`
  for staging testing, the 10-SKU live comparison, critical fail-safe test, and production go-live.
  All 7 phases complete with 100% test pass rate.
