# GCO Stock Sync — Master Implementation Plan

## Project Overview

**Plugin:** GCO Supplier Stock Sync  
**Client:** Gun Cabinets Online (guncabinetsonline.co.uk) 
**Plugin Author:** Suliman K. (sulimankhan.pro)  
**Purpose:** Pull live stock data from supplier feeds and update WooCommerce product stock **status** (never quantity) on a schedule.  
**First Supplier:** Highland Outdoors (NetSuite SuiteAnalytics web query endpoint)  
**Scale:** ~70 SKUs — small dataset, no batching needed.

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────┐
│                WordPress / WooCommerce               │
│                                                     │
│  ┌──────────────┐    ┌──────────────────────────┐   │
│  │  WP-Cron /   │───▶│   Sync Runner            │   │
│  │  Manual Run  │    │   (class-sync-runner.php) │   │
│  └──────────────┘    └────────┬─────────────────┘   │
│                               │                     │
│          ┌────────────────────┼────────────────┐    │
│          ▼                    ▼                ▼    │
│  ┌───────────────┐  ┌───────────────┐  ┌────────┐  │
│  │ Highland      │  │ Supplier #2   │  │ ...    │  │
│  │ Outdoors      │  │ (future)      │  │        │  │
│  │ Connector     │  │               │  │        │  │
│  └──────┬────────┘  └───────────────┘  └────────┘  │
│         │                                           │
│         ▼                                           │
│  ┌───────────────┐  ┌──────────────┐               │
│  │ Product       │  │ Logger /     │               │
│  │ Matcher       │  │ DB Tables    │               │
│  │ (SKU → WC)    │  │ (runs/items) │               │
│  └───────────────┘  └──────────────┘               │
│                                                     │
│  ┌──────────────────────────────────────────────┐   │
│  │  Admin UI                                     │   │
│  │  • Settings Page  • Log Page  • Product Meta │   │
│  └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
          │
          ▼ (wp_remote_get)
┌─────────────────────────┐
│ NetSuite Web Query      │
│ (Highland Outdoors Feed)│
└─────────────────────────┘
```

---

## Hard Requirements (Client-Confirmed)

1. Match products by SKU (`Name` column ↔ WooCommerce SKU)
2. Sync runs every 30–60 minutes, interval configurable
3. Storefront shows **in stock / out of stock only** — never the number
4. Out of stock threshold is **exactly 0**, no buffer
5. **A failed supplier connection must NEVER mark products out of stock** — this is the #1 safety rule
6. Log page in wp-admin: last sync time, per-product supplier qty, errors
7. Per-product enable/disable toggle for sync inclusion
8. Architecture must support adding more suppliers later without a rewrite

## Non-Goals (Do NOT Build)

- Price syncing
- Competitor price monitoring
- Quantity display on storefront
- Product creation — sync only updates existing products

---

## Technology & Standards

| Area | Standard |
|------|----------|
| PHP Version | 7.4+ (no 8.x-only syntax) |
| WordPress | 6.0+ |
| WooCommerce | HPOS compatible, CRUD API only |
| Coding Standard | WordPress Coding Standards (PHPCS) |
| Testing | PHPUnit via WordPress test suite, wp-env |
| SQL | `$wpdb->prepare()` on all custom table queries |
| HTTP | `wp_remote_get()` only — no `file_get_contents` or raw cURL |
| Hooks/Filters | All prefixed `gco_stock_sync_` |

## Safety Principle

> When in doubt about a safety edge case, choose the option that **does nothing** rather than the option that changes stock.

---

## Phase Dependency Chain

```
Phase 1 (Research)
    └──▶ Phase 2 (Skeleton)
            └──▶ Phase 3 (Connector)
                    └──▶ Phase 4 (Sync Engine)  ← CRITICAL
                            └──▶ Phase 5 (Admin UI)
                                    └──▶ Phase 6 (Hardening)
                                            └──▶ Phase 7 (Deploy)
```

Each phase must pass its quality gate before the next begins.

---

## Database Schema

### Custom Tables (prefixed `{$wpdb->prefix}gco_ss_`)

**Runs Table** — one row per sync run:
```sql
{prefix}gco_ss_runs
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  supplier        VARCHAR(64)    NOT NULL
  started_at      DATETIME       NOT NULL
  finished_at     DATETIME       NULL
  status          VARCHAR(20)    NOT NULL   -- success|failed|partial|skipped
  rows_fetched    INT UNSIGNED   DEFAULT 0
  products_updated INT UNSIGNED  DEFAULT 0
  message         TEXT           NULL
  KEY supplier_started (supplier, started_at)
```

**Items Table** — one row per product per run:
```sql
{prefix}gco_ss_items
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  run_id        BIGINT UNSIGNED NOT NULL
  sku           VARCHAR(100)    NOT NULL
  product_id    BIGINT UNSIGNED NULL      -- null = unmatched SKU
  supplier_qty  INT             NULL
  old_status    VARCHAR(20)     NULL
  new_status    VARCHAR(20)     NULL
  action        VARCHAR(20)     NOT NULL  -- updated|unchanged|skipped|unmatched
  note          VARCHAR(255)    NULL
  KEY run_id (run_id)
  KEY sku (sku)
```

### Post Meta on Products

| Key | Purpose |
|-----|---------|
| `_gco_ss_enabled` | `yes`/`no` — per-product sync toggle (default `yes`) |
| `_gco_ss_supplier` | supplier key this product syncs from |
| `_gco_ss_last_qty` | last known supplier qty (admin only, **never storefront**) |
| `_gco_ss_last_sync` | timestamp of last successful sync |

---

## File Structure

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
├── research/
│   └── FEED-NOTES.md
└── tests/
    ├── bootstrap.php
    ├── fixtures/
    │   ├── feed-valid.html
    │   ├── feed-empty.html
    │   └── feed-garbage.html
    └── integration/
```

---

## Standing Rules (All Phases)

- WordPress Coding Standards throughout; run PHPCS with the WordPress ruleset
- PHP 7.4 compatible
- No direct SQL against WooCommerce product data — CRUD only
- `$wpdb->prepare()` on every query touching custom tables
- No `error_log` debugging left in shipped code
- Every hook and filter prefixed `gco_stock_sync_`
- Anything that could touch stock status gets a test before it's considered done
- `defined('ABSPATH') || exit;` at the top of every PHP file
