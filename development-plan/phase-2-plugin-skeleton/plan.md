# Phase 2 — Plugin Skeleton & Lifecycle

**Type:** Code  
**Goal:** Installable, activatable plugin that does nothing yet but is structurally correct.  
**Estimated Effort:** 6–8 hours  
**Prerequisite:** Phase 1 complete (FEED-NOTES.md approved)

---

## Tasks

### 2.1 — Main Plugin File (`gco-stock-sync.php`)
- Plugin header: name, description, version `1.0.0`, author, text domain `gco-stock-sync`
- `Requires PHP: 7.4`, `Requires at least: 6.0`, `WC requires at least`, `WC tested up to`
- Guard: `defined('ABSPATH') || exit;`
- Define constants: `GCO_STOCK_SYNC_VERSION`, `GCO_STOCK_SYNC_FILE`, `GCO_STOCK_SYNC_PATH`
- Bootstrap: require `class-plugin.php`, call singleton init
- Declare HPOS compatibility: `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` on `before_woocommerce_init`

### 2.2 — Plugin Class (`includes/class-plugin.php`)
- Singleton pattern
- Load dependencies
- Register activation/deactivation hooks
- Bail with admin notice if WooCommerce not active
- Register hooks and filters

### 2.3 — Activator (`includes/class-activator.php`)
- Create custom database tables (via Installer)
- Set default options
- Schedule WP-Cron event

### 2.4 — Deactivator (`includes/class-deactivator.php`)
- Clear cron schedule
- **Do NOT delete data** — tables, options, and post meta survive deactivation

### 2.5 — Installer (`includes/class-installer.php`)
- `dbDelta` schema for `gco_ss_runs` and `gco_ss_items` tables
- Stored `gco_stock_sync_db_version` option
- Upgrade routine for future schema changes
- Idempotent — safe to run multiple times

### 2.6 — Uninstall (`uninstall.php`)
- Guard: `defined('WP_UNINSTALL_PLUGIN') || exit;`
- Check "delete data on uninstall" setting
- If **off** (default): do nothing
- If **on**: drop custom tables, delete all `gco_stock_sync_*` options, delete `_gco_ss_*` post meta

### 2.7 — Logger Stub (`includes/class-logger.php`)
- Placeholder class for the logging interface
- Methods for info/warning/error that write to custom tables (implemented in Phase 4)

### 2.8 — Default Options
```php
gco_stock_sync_settings = [
    'enabled'              => false,
    'sync_interval'        => 60,        // minutes
    'delete_data_uninstall'=> false,
]
```

---

## Database Schema (created in this phase)

### `{prefix}gco_ss_runs`
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
supplier        VARCHAR(64)    NOT NULL
started_at      DATETIME       NOT NULL
finished_at     DATETIME       NULL
status          VARCHAR(20)    NOT NULL
rows_fetched    INT UNSIGNED   DEFAULT 0
products_updated INT UNSIGNED  DEFAULT 0
message         TEXT           NULL
KEY supplier_started (supplier, started_at)
```

### `{prefix}gco_ss_items`
```sql
id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
run_id        BIGINT UNSIGNED NOT NULL
sku           VARCHAR(100)    NOT NULL
product_id    BIGINT UNSIGNED NULL
supplier_qty  INT             NULL
old_status    VARCHAR(20)     NULL
new_status    VARCHAR(20)     NULL
action        VARCHAR(20)     NOT NULL
note          VARCHAR(255)    NULL
KEY run_id (run_id)
KEY sku (sku)
```

---

## Files Created in This Phase

```
gco-stock-sync/
├── gco-stock-sync.php
├── uninstall.php
├── readme.txt
├── includes/
│   ├── class-plugin.php
│   ├── class-activator.php
│   ├── class-deactivator.php
│   ├── class-installer.php
│   └── class-logger.php
├── tests/
│   ├── bootstrap.php
│   └── integration/
│       └── test-lifecycle.php
```

---

## Quality Gate

```
/wordpress-skills:wp-plugin-review .
/wordpress-skills:wp-migration-review includes/class-installer.php
```

**Gate Criteria:**
- Zero critical findings
- All warning-level findings addressed or documented as intentional
- All Phase 2 tests pass

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| dbDelta syntax errors | Tables not created | Test with wp-env, verify schema against WP docs |
| HPOS declaration wrong | WooCommerce admin warnings | Test on WC 8.x+ |
| Cron not scheduling | Sync never runs | Verify with `wp cron event list` |
