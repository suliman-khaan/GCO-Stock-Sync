# Phase 6 — Hardening, i18n, Release

**Type:** Code + QA  
**Goal:** Production-ready plugin with i18n, failure alerting, CLI command, and clean release packaging.  
**Estimated Effort:** 6–8 hours  
**Prerequisite:** Phase 5 complete (admin UI passes gate)

---

## Tasks

### 6.1 — Full i18n Pass
- Every user-facing string through `__()` / `esc_html__()` with text domain `gco-stock-sync`
- Generate `languages/gco-stock-sync.pot` translation template
- Verify no untranslated strings in admin pages

### 6.2 — Admin Email on Repeated Failure
- If N consecutive runs fail (default 3), email the site admin once
- Prevents silent multi-day breakage (e.g. if NetSuite hash rotates)
- Deduplicate: don't email every hour — track last notification time
- Store consecutive failure count in options
- Reset counter on successful run

### 6.3 — Test Connection Button
- Per-supplier "Test Connection" button on settings page
- Fetches feed and reports row count without writing anything
- Invaluable for diagnosing when the hash breaks
- AJAX handler with nonce + capability check

### 6.4 — Full Review Suite
Run all review skills across the entire plugin one final time:
```
/wordpress-skills:wp-plugin-review .
/wordpress-skills:wp-sec-review .
/wordpress-skills:wp-perf-review .
/wordpress-skills:wp-woo-review .
/wordpress-skills:wp-test-review .
```

### 6.5 — WP-CLI Command
```bash
wp gco-stock-sync run [--supplier=<key>] [--dry-run] [--force]
```
- `--supplier`: Run for a specific supplier (default: all)
- `--dry-run`: Fetch and match but don't update stock status
- `--force`: Bypass the lock
- Output: table of results (SKU, action, old/new status)
- Makes real-cron setup and debugging much easier

### 6.6 — readme.txt & Changelog
- WordPress.org-formatted `readme.txt`
- Changelog for version 1.0.0
- Version bump across all files

### 6.7 — Release Build
- Clean zip excluding: `tests/`, `node_modules/`, `.git`, `development-plan/`, dev configs
- Build script or Makefile

### 6.8 — Client Handover Doc
Written for James (the client):
- Where the settings are
- How to read the log
- How to disable sync for one product
- What to do if the feed URL stops working (contact johnb@highlandoutdoors.co.uk)
- How real cron works if they want exact timing

---

## Files Created/Modified in This Phase

```
languages/
└── gco-stock-sync.pot

includes/
├── class-cli.php                 (NEW)
└── class-failure-notifier.php    (NEW)

admin/
└── class-settings-page.php       (MODIFIED — test connection button)

readme.txt                         (MODIFIED — version, changelog)
gco-stock-sync.php                 (MODIFIED — version bump)
```

---

## Quality Gate (Final)

```
/wordpress-skills:wp-plugin-review .
/wordpress-skills:wp-sec-review .
/wordpress-skills:wp-perf-review .
/wordpress-skills:wp-woo-review .
/wordpress-skills:wp-test-review .
```

Plus WordPress Plugin Check if available.

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| i18n strings missed | Untranslatable UI | Automated scan for bare strings |
| Email flooding | Client spammed | Dedup logic + configurable threshold |
| CLI command crashes | Bad debugging experience | Full error handling, WP_CLI::error() |
| Release includes dev files | Larger zip, potential security | Build script with explicit excludes |
