# Phase 5 — Admin UI

**Type:** Code  
**Goal:** The client can see and control everything without touching code.  
**Estimated Effort:** 8–10 hours  
**Prerequisite:** Phase 4 complete (sync engine passes gate)

---

## Tasks

### 5.1 — Admin Menu & Page Registration (`admin/class-admin.php`)
- Register top-level menu or submenu under WooCommerce
- Enqueue admin assets only on this plugin's screens (`get_current_screen()` check)
- Load subpage classes

### 5.2 — Settings Page (`admin/class-settings-page.php`)

Settings using **Settings API** (`register_setting`, `add_settings_section`, `add_settings_field`):

| Setting | Type | Default | Validation |
|---------|------|---------|------------|
| Enable sync globally | checkbox | OFF | boolean |
| Sync interval | select (30/60/120 min) | 60 | in_array check |
| Delete data on uninstall | checkbox | OFF | boolean |

Per-supplier section (generated from `get_settings_fields()`):
- Highland Outdoors → feed URL, enabled toggle, min-rows threshold

Additional elements:
- **"Run sync now" button** — nonce + capability checked AJAX handler
- **Status panel:** last run time, result, next scheduled run
- **WP-Cron warning** if `DISABLE_WP_CRON` is defined true
- Real-cron alternative: `wget -q -O - https://guncabinetsonline.co.uk/wp-cron.php?doing_wp_cron`

### 5.3 — Log Page (`admin/class-log-page.php`, `admin/class-log-list-table.php`)

- `WP_List_Table` subclass listing runs, newest first, paginated
- Columns: run time, supplier, status (colour-coded), rows fetched, products updated, message
- Click a run → item detail view:
  - SKU, matched product (linked to edit screen), supplier qty, old → new status, action, note
- Filters: by status, by action (for items)
- Manual "clear logs" action — nonce-protected
- Auto-purge: daily cron deletes runs older than N days (default 30)

### 5.4 — Product Meta Box (`admin/class-product-meta-box.php`)

- Checkbox in the WooCommerce Inventory tab (via `woocommerce_product_options_inventory_product_data`):
  "Sync stock from supplier"
- Read-only display: last supplier qty, last sync time
- Save via `woocommerce_process_product_meta`, nonce + capability checked
- Variation support if variations are in scope

### 5.5 — Products List Column (optional but useful)

- "Stock Sync" column on the products list
- Small icon: synced ✓ / disabled ✗ / unmatched ⚠
- Quick visual for the ~70 products

### 5.6 — Admin Assets (`admin/assets/`)
- CSS for log page styling, status colours, icons
- JS for AJAX "Run sync now" button
- Enqueued only on plugin pages

---

## Security Requirements (ALL of Phase 5)

| Requirement | Implementation |
|-------------|---------------|
| CSRF protection | `wp_nonce_field()` + `check_admin_referer()` on every form |
| Capability check | `current_user_can('manage_woocommerce')` on every handler |
| Output escaping | `esc_html()` / `esc_attr()` / `esc_url()` on every output |
| Input sanitisation | Sanitised on input, never trusted from `$_POST` / `$_GET` |
| Asset isolation | Admin CSS/JS loaded only on plugin's own screens |

---

## Files Created in This Phase

```
admin/
├── class-admin.php
├── class-settings-page.php
├── class-log-page.php
├── class-log-list-table.php
├── class-product-meta-box.php
└── assets/
    ├── admin.css
    └── admin.js

tests/integration/
├── test-admin-settings.php
├── test-admin-log.php
└── test-admin-product-meta.php
```

---

## Quality Gate

```
/wordpress-skills:wp-admin-review admin
/wordpress-skills:wp-sec-review .
/wordpress-skills:wp-a11y-review admin
```

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Settings API misconfiguration | Settings not saving | Test save/load cycle |
| XSS via unescaped output | Security vulnerability | Escape every output |
| CSRF on manual sync | Unauthorised sync trigger | Nonce verification |
| Global asset loading | Conflicts with other plugins | `get_current_screen()` guard |
