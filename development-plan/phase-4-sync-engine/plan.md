# Phase 4 — Sync Engine

**Type:** Code (CRITICAL PHASE)  
**Goal:** Take a fetch result and update WooCommerce. This is where the safety rules live.  
**Estimated Effort:** 8–12 hours  
**Prerequisite:** Phase 3 complete (connector passes gate)

> [!CAUTION]
> This is the most critical phase. Every safety rule in the plugin is enforced here. Errors can mark real products out of stock on the live storefront.

---

## Tasks

### 4.1 — Sync Runner (`includes/sync/class-sync-runner.php`)

Algorithm:
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

### 4.2 — Product Matcher (`includes/sync/class-product-matcher.php`)
- Match via `wc_get_product_id_by_sku()`
- Case-insensitive, whitespace-trimmed on both sides
- Log when match only succeeded after normalisation
- Handle variations: if SKU resolves to a variation, update the variation
- Log if SKU resolves to multiple products

### 4.3 — Sync Result (`includes/sync/class-sync-result.php`)
- Value object carrying run outcome data
- Status, counts, item details

### 4.4 — Safety Rules (NON-NEGOTIABLE)
1. **Never write `stock_quantity`** — only `set_stock_status()`
2. Leave `manage_stock` off for synced products; if on, log a warning
3. Use **WooCommerce CRUD** exclusively — no direct `update_post_meta` on stock fields
4. Never direct SQL against `wp_posts`/`wp_postmeta` for product data
5. Products NOT in the feed are left completely alone
6. Wrap per-product loop in try/catch — one product's exception can't abort the run
7. **A failed fetch NEVER touches any product**

### 4.5 — Cron Integration
- `wp_schedule_event` with custom interval via `cron_schedules` filter
- Interval configurable: 30 / 60 / 120 minutes (default 60)
- Reschedule cleanly when setting changes
- Detect and warn if `DISABLE_WP_CRON` is defined true

### 4.6 — Locking Mechanism
- Transient-based lock prevents overlapping runs
- 15 min TTL (exceeds longest plausible run)
- Always release in a `finally` equivalent path
- Fatal error cannot wedge the lock permanently

---

## Files Created in This Phase

```
includes/sync/
├── class-sync-runner.php
├── class-sync-result.php
└── class-product-matcher.php

tests/integration/
├── test-sync-runner.php
└── test-product-matcher.php
```

---

## Quality Gate

```
/wordpress-skills:wp-woo-review includes/sync
/wordpress-skills:wp-perf-review includes/sync
/wordpress-skills:wp-test-review .
```

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Stock status update logic error | Products incorrectly marked OOS | Comprehensive test coverage, manual verification |
| Lock not released on fatal | Sync wedged for 15 min | `finally` block + TTL fallback |
| WooCommerce CRUD API changes | Updates silently fail | Pin WC version, test on target version |
| Variation SKU handling edge case | Parent updated instead of variation | Explicit variation detection + tests |
