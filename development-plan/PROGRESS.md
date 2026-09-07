# GCO Stock Sync — Progress Tracker

**Read this file first, before anything else in `development-plan/`.** It's the
handoff doc between sessions/AIs. If you're picking this project up cold, this
tells you exactly where things stand, what to verify, and what's next.

Update this file whenever you finish a task, pass/fail a gate, or discover
something the next person needs to know. Keep it current — stale status here
is worse than no status at all.

---

## Current Status

**Active phase:** Phase 3 — Supplier Abstraction + Highland Outdoors Connector
**Phase state:** Code + tests written, **all 14 tests passing**. Quality-gate reviews (`wp-plugin-review` / `wp-sec-review`) not yet run — that's the one remaining item before sign-off.

| Phase | Status |
|-------|--------|
| 1 — Feed Reconnaissance | ✅ Done — `research/FEED-NOTES.md` complete, fixtures created |
| 2 — Plugin Skeleton & Lifecycle | ✅ Done — passes `test-lifecycle.php` (17/17) |
| 3 — Supplier Connector | 🔶 Code done, tests passing (14/14) — quality gate reviews still pending |
| 4 — Sync Engine | ⬜ Not started |
| 5 — Admin UI | ⬜ Not started |
| 6 — Hardening & Release | ⬜ Not started |
| 7 — Deployment & Handover | ⬜ Not started |

---

## Immediate Next Step (start here)

Full suite confirmed green on 2026-09-07:

```
/c/wamp64/bin/php/php8.2.29/php.exe tests/run-tests.php
→ Summary: 31 tests, 31 passed, 0 failed
```

(17 lifecycle tests from Phase 2 + 14 supplier tests from Phase 3. Note: the
plan doc lists 16 Phase 3 test IDs (P3-TC01–16), but TC09/TC10/TC11 — the qty
normalisation cases for commas/negative/empty — were implemented as one
combined test method (`test_qty_normalisation_rules`) rather than three
separate ones. All three assertions are present; it's just consolidated.)

**Before starting Phase 4**, run the two quality-gate commands specified in
`phase-3-supplier-connector/plan.md` (not yet run this session):

```
/wordpress-skills:wp-plugin-review includes/suppliers
/wordpress-skills:wp-sec-review includes/suppliers
```

Once those pass (or any findings are fixed), mark Phase 3 ✅ in the table
above and move to `phase-4-sync-engine/plan.md`. Phase 4 is flagged
**CRITICAL** in the master plan — it's the code that actually changes
WooCommerce stock status, so re-read the Safety Principle in
`master-plan.md` before writing it.

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

Tests: ✅ done (31/31 passing, see "Immediate Next Step").
Skill reviews: ⬜ **Not yet run** — do this before moving to Phase 4.

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
  connector, 14 integration tests). Full suite run against local WP:
  **31/31 passing**. This file created. Remaining before Phase 3 sign-off:
  `wp-plugin-review` and `wp-sec-review` on `includes/suppliers`.
