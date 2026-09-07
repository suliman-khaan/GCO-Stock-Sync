# GCO Supplier Stock Sync — Plugin Build Plan

**Client:** Gun Cabinets Online (guncabinetsonline.co.uk)
**Slug:** `gco-stock-sync`
**Text domain:** `gco-stock-sync`
**Prefix:** `GCO_Stock_Sync_` (classes), `gco_stock_sync_` (functions/hooks/options)
**Approach:** Self-contained WordPress plugin. No n8n, no external service.

---

## 0. Context for the agent

Read this section first before writing any code.

### What this plugin does

Pulls live stock data from supplier feeds and updates WooCommerce product
stock **status** (never quantity) on a schedule.

First supplier: **Highland Outdoors**, exposed as a NetSuite SuiteAnalytics
web query endpoint:

```
https://687183.app.netsuite.com/app/reporting/webquery.nl
  ?compid=687183
  &entity=-5
  &email=johnb@highlandoutdoors.co.uk
  &role=1050
  &cr=1965
  &hash=AAEJ7tMQgQelTtsA_bfgPhqV1JBNZ5sMB754qObS-sQzpt41Nuw
```

Response format is **unconfirmed** — NetSuite web queries typically return an
HTML `<table>`, sometimes tab-delimited text. Phase 1 must confirm this
against the live endpoint before the parser is written.

Known column layout (from the client's .xlsx):

| Col | Header | Notes |
|---|---|---|
| A | Qty Available | integer |
| B | Trade Price | may be blank on accessories |
| C | Name | **this is the SKU** — matches WooCommerce SKU exactly |
| D | Brand Name | Boston Security / Buffalo River |
| E | Description | long text, unused |
| F | Internal ID | NetSuite ID, store for reference |
| G | Trade Price | duplicate column |
| H | Qty Available | duplicate column |

**Critical parsing gotcha:** the feed contains non-product rows. Brand/group
header rows appear with `Name` values like `Boston Security` or `Cabinets`,
qty `0`, and no price. These are section headers, not products, and must be
filtered out — otherwise the sync will treat them as SKUs.

### Hard requirements (client-confirmed)

1. Match products by SKU (`Name` column ↔ WooCommerce SKU)
2. Sync runs every 30–60 minutes, interval configurable
3. Storefront shows **in stock / out of stock only** — never the number
4. Out of stock threshold is **exactly 0**, no buffer
5. **A failed supplier connection must NEVER mark products out of stock.**
   This is the single most important safety rule in the plugin. On any fetch
   error, parse error, or suspiciously empty response, the sync aborts
   without touching a single product.
6. Log page in wp-admin: last sync time, per-product supplier qty, errors
7. Per-product enable/disable toggle for sync inclusion
8. Architecture must support adding more suppliers later without a rewrite

### Scale

~70 SKUs total; currently only Boston Security and Buffalo River categories
are live. Small dataset — no batching or queue system needed. Keep it simple.

### Non-goals (do not build)

- Price syncing (feed has trade price; client did not ask for price updates)
- Competitor price monitoring (client asked, deferred to a later quote)
- Quantity display on the storefront
- Product creation — sync only ever updates products that already exist

---

## 0.1 Workflow for each phase

For every phase below:

1. Implement the phase.
2. Write the tests specified in that phase's **Tests** block.
3. Run the review gate for that phase (slash commands listed per phase).
4. Fix anything Critical or Warning.
5. Commit with the phase name. Only then move to the next phase.

Review skills available (from `wordpress-skills` plugin):

```
/wordpress-skills:wp-plugin-review   architecture, lifecycle, standards
/wordpress-skills:wp-sec-review      XSS, CSRF, nonce, capability, injection
/wordpress-skills:wp-woo-review      HPOS, CRUD APIs, Woo compatibility
/wordpress-skills:wp-admin-review    settings pages, menus, notices
/wordpress-skills:wp-perf-review     queries, cron, caching
/wordpress-skills:wp-test-review     coverage gaps
/wordpress-skills:wp-migration-review dbDelta, schema, upgrade safety
```

### Test environment

Use `wp-env` (Docker) for integration tests — it gives a real WP + Woo
install, which is necessary because most of this plugin is DB and Woo CRUD
work that can't be meaningfully unit tested in isolation.

```bash
npm i -D @wordpress/env
npx wp-env start
npx wp-env run tests-cli wp plugin install woocommerce --activate
```

PHPUnit via the WordPress test suite. Aim for integration tests over unit
tests — mock only the HTTP layer.

---

## Phase 1 — Feed reconnaissance (no plugin code)

**Goal:** know exactly what the endpoint returns before writing a parser.

### Tasks

1. `curl` the endpoint, save raw response to `research/feed-sample.html`
2. Determine: HTML table? TSV? Does it need auth headers or cookies?
3. Confirm the `email` param works as a plain GET value (in Excel it's an
   interactive prompt, but it's stored as a static string, so it should)
4. Note exact header row text, how many junk/header rows precede data, and
   how section-header rows are distinguishable from product rows
5. Save 2–3 fixture files to `tests/fixtures/`:
   - `feed-valid.html` — real successful response
   - `feed-empty.html` — response with headers but zero product rows
   - `feed-garbage.html` — an error page / HTML that isn't the feed

### Deliverable

`research/FEED-NOTES.md` documenting the response shape, the exact rule for
identifying a valid product row, and any auth quirks.

**If the endpoint doesn't resolve or the hash has expired**, stop and flag it
— the client needs to go back to Highland Outdoors (contact:
johnb@highlandoutdoors.co.uk). Do not build a parser against guesses.

### Gate

Human review of FEED-NOTES.md. No slash commands this phase.

---

## Phase 2 — Plugin skeleton and lifecycle

**Goal:** installable, activatable plugin that does nothing yet but is
structurally correct.

### File structure

```
gco-stock-sync/
├── gco-stock-sync.php            # header, constants, bootstrap only
├── uninstall.php
├── readme.txt
├── includes/
│   ├── class-plugin.php          # singleton, loads deps, registers hooks
│   ├── class-activator.php       # tables, default options, schedule
│   ├── class-deactivator.php     # clear schedule (never drop data)
│   ├── class-installer.php       # dbDelta schema + version upgrades
│   └── class-logger.php
├── includes/suppliers/
│   ├── interface-supplier.php
│   ├── abstract-supplier.php
│   └── class-highland-outdoors.php
├── includes/sync/
│   ├── class-sync-runner.php
│   ├── class-sync-result.php
│   └── class-product-matcher.php
├── admin/
│   ├── class-admin.php
│   ├── class-settings-page.php
│   ├── class-log-page.php
│   ├── class-log-list-table.php
│   ├── class-product-meta-box.php
│   └── assets/
├── languages/
└── tests/
    ├── bootstrap.php
    ├── fixtures/
    └── integration/
```

### Tasks

1. Plugin header: name, description, version `1.0.0`, author, text domain,
   `Requires PHP: 7.4`, `Requires at least: 6.0`, `WC requires at least`,
   `WC tested up to`
2. Guard: `defined('ABSPATH') || exit;` at the top of every file
3. Declare **HPOS compatibility** —
   `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`
   on `before_woocommerce_init`. (Not strictly needed since we don't touch
   orders, but Woo will warn without it.)
4. Bail with an admin notice if WooCommerce is not active
5. Activation: create tables, set default options, schedule cron
6. Deactivation: unschedule cron only — **do not delete data**
7. `uninstall.php`: drop tables + options, guarded by
   `WP_UNINSTALL_PLUGIN` check, behind a "delete data on uninstall" setting
   that defaults to **off**
8. `class-installer.php` with a stored `gco_stock_sync_db_version` option and
   an upgrade routine, so schema changes in future supplier phases are safe

### Database schema

Two custom tables (`dbDelta`, prefixed `{$wpdb->prefix}gco_ss_`):

```sql
-- sync run history (one row per run)
{prefix}gco_ss_runs
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  supplier      VARCHAR(64)   NOT NULL
  started_at    DATETIME      NOT NULL
  finished_at   DATETIME      NULL
  status        VARCHAR(20)   NOT NULL   -- success|failed|partial|skipped
  rows_fetched  INT UNSIGNED  DEFAULT 0
  products_updated INT UNSIGNED DEFAULT 0
  message       TEXT          NULL
  KEY supplier_started (supplier, started_at)

-- per-product outcome (one row per product per run)
{prefix}gco_ss_items
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  run_id      BIGINT UNSIGNED NOT NULL
  sku         VARCHAR(100)  NOT NULL
  product_id  BIGINT UNSIGNED NULL      -- null = unmatched SKU
  supplier_qty INT          NULL
  old_status  VARCHAR(20)   NULL
  new_status  VARCHAR(20)   NULL
  action      VARCHAR(20)   NOT NULL    -- updated|unchanged|skipped|unmatched
  note        VARCHAR(255)  NULL
  KEY run_id (run_id)
  KEY sku (sku)
```

Post meta on products:

| Key | Purpose |
|---|---|
| `_gco_ss_enabled` | `yes`/`no` — per-product sync toggle (default `yes`) |
| `_gco_ss_supplier` | supplier key this product syncs from |
| `_gco_ss_last_qty` | last known supplier qty (admin diagnostics only) |
| `_gco_ss_last_sync` | timestamp of last successful sync for this product |

`_gco_ss_last_qty` is admin-only. It must never be rendered on the
storefront — that would leak quantity, which the client explicitly ruled out.

### Tests

- Activation creates both tables with expected columns
- Activation is idempotent (run twice, no errors, no duplicate schema)
- Deactivation clears cron but leaves tables and data intact
- Uninstall with the delete-data setting off leaves tables intact
- Uninstall with it on drops tables and options
- Plugin shows an admin notice and self-disables its hooks when Woo inactive

### Gate

```
/wordpress-skills:wp-plugin-review .
/wordpress-skills:wp-migration-review includes/class-installer.php
```

---

## Phase 3 — Supplier abstraction + Highland Outdoors connector

**Goal:** fetch and parse the feed into a normalised array. No WooCommerce
interaction at all in this phase.

### Interface

```php
interface GCO_Stock_Sync_Supplier_Interface {
    public function get_key(): string;              // 'highland_outdoors'
    public function get_label(): string;            // 'Highland Outdoors'
    public function get_settings_fields(): array;   // for the settings screen
    public function is_configured(): bool;
    /**
     * @return GCO_Stock_Sync_Fetch_Result
     * Never throws. Always returns a result object with ok/error state.
     */
    public function fetch(): GCO_Stock_Sync_Fetch_Result;
}
```

`GCO_Stock_Sync_Fetch_Result` carries: `ok` (bool), `items` (array of
`['sku','qty','name','internal_id']`), `error_code`, `error_message`,
`raw_row_count`.

New suppliers are registered via a filter so future connectors drop in
without editing core:

```php
apply_filters( 'gco_stock_sync_suppliers', $suppliers );
```

### Highland Outdoors connector

1. Feed URL stored as a plugin **setting**, not hardcoded — the hash may
   rotate and the client must be able to paste a new URL without a code
   change. Ship the known URL as the default value.
2. Fetch via `wp_remote_get()` with a 30s timeout and a sane user agent.
   Never `file_get_contents` or raw cURL.
3. Treat as a fetch failure: `WP_Error`, HTTP status not 200, empty body,
   body that doesn't contain the expected header signature.
4. Parse using `DOMDocument` + `DOMXPath` (with `libxml_use_internal_errors`)
   if HTML, per Phase 1 findings. **No regex HTML parsing.**
5. Row filter — a row is a product only if: SKU cell is non-empty, SKU does
   not match a known section-header value, and qty parses as an integer.
   Derive the exact rule from FEED-NOTES.md.
6. Normalise qty: strip commas/whitespace, cast to int, negative → 0.
7. Store the raw response body of the last failed fetch (truncated to ~10KB)
   in a transient for debugging on the log page.

### Sanity guard (belongs here, not in the sync runner)

If the feed parses successfully but yields **zero product rows**, or fewer
than a configurable minimum (default 10), return `ok = false` with error code
`suspiciously_empty`. A feed that returns an empty table is
indistinguishable from "everything is out of stock" and must be treated as a
failure, not as data.

### Tests

Using Phase 1 fixtures, with `pre_http_request` filtered to serve them:

- Valid fixture → correct SKU count, qty values match, section-header rows
  excluded
- Empty fixture → `ok = false`, code `suspiciously_empty`
- Garbage fixture → `ok = false`, code `parse_failed`
- `WP_Error` from HTTP → `ok = false`, code `fetch_failed`
- HTTP 500 → `ok = false`
- HTTP 200 with empty body → `ok = false`
- Qty normalisation: `"1,234"` → 1234, `"-5"` → 0, `""` → skipped row
- A second registered dummy supplier is discoverable via the filter

### Gate

```
/wordpress-skills:wp-plugin-review includes/suppliers
/wordpress-skills:wp-sec-review includes/suppliers
```

---

## Phase 4 — Sync engine

**Goal:** take a fetch result and update WooCommerce. This is where the
safety rules live.

### Runner algorithm

```
1. Acquire lock (transient, 15 min TTL). If locked → log 'skipped', return.
2. Open a run row (status = 'running').
3. supplier->fetch()
4. If !result->ok:
       log run as 'failed' with error
       DO NOT TOUCH ANY PRODUCT
       release lock, return
5. For each item:
       a. product_id = matcher->find_by_sku(item.sku)
       b. if none → record 'unmatched', continue
       c. if _gco_ss_enabled === 'no' → record 'skipped', continue
       d. new_status = item.qty > 0 ? 'instock' : 'outofstock'
       e. if new_status === current status → record 'unchanged',
          still update _gco_ss_last_qty and _gco_ss_last_sync
       f. else → update status via Woo CRUD, record 'updated'
6. Close run row: status 'success', counts, finished_at.
7. Release lock.
```

### Rules the implementation must obey

- **Never write `stock_quantity`.** Only `set_stock_status()`. Leave
  `manage_stock` off for synced products. If a product has manage_stock on,
  setting status alone can behave oddly — detect this and log a warning
  rather than silently fighting Woo.
- Use **WooCommerce CRUD** (`wc_get_product()`, `$product->set_stock_status()`,
  `$product->save()`). Never direct `update_post_meta` on stock fields, and
  never direct SQL against `wp_posts`/`wp_postmeta` for product data.
- Match SKU via `wc_get_product_id_by_sku()`. Handle variations: if the SKU
  resolves to a variation, update the variation. Log if a SKU resolves to
  multiple products.
- SKU matching should be case-insensitive and whitespace-trimmed on both
  sides, but log when a match only succeeded after normalisation so the
  client can clean up their data.
- Products in the site that are **not** in the feed are left completely
  alone. Absence from the feed is not evidence of anything.
- Wrap the per-product loop so one product's exception can't abort the run.

### Cron

- `wp_schedule_event` with a custom interval registered via `cron_schedules`
- Interval configurable: 30 / 60 / 120 minutes (default 60)
- Reschedule cleanly when the setting changes
- **WP-Cron caveat:** it only fires on page visits. Add a settings-page note
  explaining this and giving the real-cron alternative:
  ```
  wget -q -O - https://guncabinetsonline.co.uk/wp-cron.php?doing_wp_cron
  ```
  Also detect and warn if `DISABLE_WP_CRON` is defined true.
- Manual "Run sync now" button on the settings page (nonce + capability
  checked), so you and the client can trigger without waiting.

### Locking

Transient-based lock prevents overlapping runs if cron doubles up. TTL must
exceed the longest plausible run. Always release in a `finally`-equivalent
path so a fatal doesn't wedge the lock permanently.

### Tests

The critical ones — these are the tests that protect the client's storefront:

- **Fetch failure leaves every product's stock status untouched.** Seed
  products as instock, force a fetch failure, assert nothing changed. Run
  this for each failure mode: WP_Error, HTTP 500, empty body, garbage body,
  suspiciously-empty feed.
- qty 5 → instock; qty 0 → outofstock; qty 1 → instock (boundary, no buffer)
- Product with `_gco_ss_enabled = 'no'` is never touched even when the feed
  says otherwise
- SKU in feed but not on site → recorded as unmatched, no error, run still
  succeeds
- Product on site but not in feed → untouched
- `stock_quantity` is never written — assert it's identical before/after
- Already-correct status → recorded 'unchanged', no unnecessary `save()`
- Variation SKU resolves and updates the variation, not the parent
- Lock: second concurrent run exits as 'skipped'
- Run row and item rows are written with correct counts
- An exception on one product doesn't abort the remaining products

### Gate

```
/wordpress-skills:wp-woo-review includes/sync
/wordpress-skills:wp-perf-review includes/sync
/wordpress-skills:wp-test-review .
```

---

## Phase 5 — Admin UI

**Goal:** the client can see and control everything without touching code.

### Settings page (`WooCommerce → Stock Sync`, or its own top-level menu)

- Enable/disable sync globally (master switch, default **off** until
  configured)
- Sync interval selector
- Per-supplier section, generated from `get_settings_fields()`:
  Highland Outdoors → feed URL, enabled toggle, minimum-rows threshold
- "Run sync now" button
- Status panel: last run time, result, next scheduled run
- WP-Cron warning if `DISABLE_WP_CRON` is on
- "Delete all data on uninstall" checkbox (default off)

Use the **Settings API** (`register_setting`, `add_settings_section`,
`add_settings_field`) with a proper `sanitize_callback` per field. Feed URL
sanitised with `esc_url_raw` and validated as http(s).

### Log page

- `WP_List_Table` subclass listing runs, newest first, paginated
- Columns: run time, supplier, status (colour-coded), rows fetched, products
  updated, message
- Click a run → item detail: SKU, matched product (linked), supplier qty,
  old → new status, action, note
- Filter by status; filter items by action
- Manual "clear logs" action, nonce-protected
- Retention: auto-purge runs older than N days (default 30) on a daily cron,
  so the tables don't grow forever

### Product edit screen

- Checkbox in the Inventory tab (via
  `woocommerce_product_options_inventory_product_data`): "Sync stock from
  supplier"
- Show read-only last supplier qty + last sync time next to it
- Save via `woocommerce_process_product_meta`, nonce + capability checked
- Same field on the variation panel if variations are in scope
- Bulk enable/disable via the products list bulk actions menu (nice-to-have)

### Products list column (optional but useful)

A "Stock Sync" column showing a small icon: synced / disabled / unmatched.
Makes it obvious at a glance which of the ~70 are wired up.

### Security requirements for this whole phase

- Every form: `wp_nonce_field()` + `check_admin_referer()`
- Every handler: `current_user_can('manage_woocommerce')`
- Every output: `esc_html()` / `esc_attr()` / `esc_url()`
- Every input: sanitised on the way in, never trusted from `$_POST`
- Admin assets enqueued only on this plugin's screens (check
  `get_current_screen()`), never globally

### Tests

- Settings save with valid nonce persists; invalid nonce rejected
- Non-privileged user gets denied on every handler
- Feed URL sanitisation rejects `javascript:` and non-http schemes
- Product meta box saves the toggle correctly, defaults to enabled
- Log list table renders and paginates with seeded data
- Log purge deletes only rows older than the retention window
- Manual run trigger requires nonce + capability

### Gate

```
/wordpress-skills:wp-admin-review admin
/wordpress-skills:wp-sec-review .
/wordpress-skills:wp-a11y-review admin
```

---

## Phase 6 — Hardening, i18n, release

### Tasks

1. Full i18n pass: every user-facing string through `__()` / `esc_html__()`
   with the `gco-stock-sync` text domain. Generate `languages/gco-stock-sync.pot`.
2. Admin email on repeated failure — if N consecutive runs fail (default 3),
   email the site admin once. Prevents silent multi-day breakage if the
   NetSuite hash rotates. Deduplicate so it doesn't email every hour.
3. Add a "test connection" button per supplier: fetches and reports row count
   without writing anything. Invaluable when the hash breaks.
4. Run the full review suite one more time across the whole plugin.
5. WP-CLI command (`wp gco-stock-sync run`) — small, and makes real-cron
   setup and debugging much easier.
6. `readme.txt`, changelog, version bump, build a clean zip excluding
   `tests/`, `node_modules/`, `.git`, dev configs.
7. Write the client handover doc (see below).

### Final gate

```
/wordpress-skills:wp-plugin-review .
/wordpress-skills:wp-sec-review .
/wordpress-skills:wp-perf-review .
/wordpress-skills:wp-woo-review .
/wordpress-skills:wp-test-review .
```

Plus WordPress Plugin Check if available.

---

## Phase 7 — Deployment and handover

1. Install on **staging first**, never straight to live
2. Seed the SKU mapping — confirm with the client which of the ~70 are live
3. Run manual sync with the master switch on but verify against staging data
4. Compare a sample of 10 SKUs against the live feed by hand
5. Deliberately break the feed URL and confirm nothing goes out of stock
6. Go live, watch the first three automatic runs
7. Handover doc for James covering: where the settings are, how to read the
   log, how to disable sync for one product, what to do if the feed URL
   stops working (contact johnb@highlandoutdoors.co.uk for a fresh URL, paste
   it into settings), and how real cron works if they want exact timing

---

## Adding supplier #2 later

The whole point of the abstraction. When the next supplier's integration
method is known:

1. New class in `includes/suppliers/` implementing the interface
2. Register it on the `gco_stock_sync_suppliers` filter
3. Declare its settings fields
4. Add fixtures + connector tests
5. Set `_gco_ss_supplier` on the relevant products

Nothing in the sync runner, admin UI, logging, or cron should need to change.
If it does, the abstraction is wrong — fix it in this phase rather than
special-casing.

---

## Standing rules for the whole build

- WordPress Coding Standards throughout; run PHPCS with the WordPress ruleset
- PHP 7.4 compatible (don't assume 8.x syntax)
- No direct SQL against WooCommerce product data — CRUD only
- `$wpdb->prepare()` on every query touching our own tables
- No `error_log` debugging left in shipped code
- Every hook and filter prefixed `gco_stock_sync_`
- Anything that could touch stock status gets a test before it's considered
  done
- When in doubt about a safety edge case, choose the option that does nothing
  rather than the option that changes stock
