# Phase 5 — Test Cases (Admin UI)

**Phase Type:** Automated Integration Tests  
**Test Files:** `tests/integration/test-admin-settings.php`, `tests/integration/test-admin-log.php`, `tests/integration/test-admin-product-meta.php`  
**Test Framework:** PHPUnit via WordPress test suite

---

## Test Case Summary

| ID | Test | Priority | Type |
|----|------|----------|------|
| P5-TC01 | Settings save with valid nonce persists | Critical | Integration |
| P5-TC02 | Settings save with invalid nonce rejected | Critical | Integration |
| P5-TC03 | Non-privileged user denied on settings save | Critical | Integration |
| P5-TC04 | Feed URL sanitisation rejects `javascript:` | Critical | Integration |
| P5-TC05 | Feed URL sanitisation rejects non-http schemes | Critical | Integration |
| P5-TC06 | Feed URL sanitisation accepts valid https URL | High | Integration |
| P5-TC07 | Sync interval only accepts allowed values | High | Integration |
| P5-TC08 | Product meta box saves toggle correctly | High | Integration |
| P5-TC09 | Product meta toggle defaults to enabled | Medium | Integration |
| P5-TC10 | Log list table renders with seeded data | High | Integration |
| P5-TC11 | Log list table paginates correctly | Medium | Integration |
| P5-TC12 | Log purge deletes only old rows | High | Integration |
| P5-TC13 | Log purge respects retention window | High | Integration |
| P5-TC14 | Manual run trigger requires nonce | Critical | Integration |
| P5-TC15 | Manual run trigger requires capability | Critical | Integration |
| P5-TC16 | Non-privileged user denied on manual run | Critical | Integration |
| P5-TC17 | Non-privileged user denied on log clear | Critical | Integration |
| P5-TC18 | Admin assets loaded only on plugin screens | Medium | Integration |
| P5-TC19 | Status panel shows last run info | Medium | Integration |
| P5-TC20 | WP-Cron warning shown when DISABLE_WP_CRON | Medium | Integration |
| P5-TC21 | Run detail view shows item-level data | Medium | Integration |
| P5-TC22 | Settings change reschedules cron | High | Integration |

---

## Detailed Test Cases

### P5-TC01: Settings Save with Valid Nonce Persists

**Priority:** Critical  
**Method:** `test_settings_save_valid_nonce()`

**Preconditions:**
- User is admin with `manage_woocommerce` capability
- Plugin activated

**Steps:**
```php
// 1. Set current user to admin
wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

// 2. Simulate form submission with valid nonce
$_POST['_wpnonce'] = wp_create_nonce('gco_stock_sync_settings');
$_POST['gco_stock_sync_settings'] = [
    'enabled'       => true,
    'sync_interval' => 30,
];

// 3. Process settings save
// 4. Retrieve saved settings
$settings = get_option('gco_stock_sync_settings');
```

**Expected Result:**
- `$settings['enabled'] === true`
- `$settings['sync_interval'] === 30`

---

### P5-TC02: Settings Save with Invalid Nonce Rejected

**Priority:** Critical  
**Method:** `test_settings_save_invalid_nonce_rejected()`

**Steps:**
```php
// 1. Set current user to admin
// 2. Submit with bad nonce
$_POST['_wpnonce'] = 'invalid_nonce_value';
$_POST['gco_stock_sync_settings'] = ['enabled' => true];

// 3. Attempt save
```

**Expected Result:**
- Settings NOT saved
- Response is a `wp_die()` or 403 error

---

### P5-TC03: Non-Privileged User Denied on Settings

**Priority:** Critical  
**Method:** `test_non_privileged_user_denied_settings()`

**Steps:**
```php
// 1. Set current user to subscriber
wp_set_current_user($this->factory->user->create(['role' => 'subscriber']));

// 2. Submit with valid nonce
// 3. Attempt settings save
```

**Expected Result:**
- Denied (`current_user_can('manage_woocommerce')` check fails)
- Settings NOT modified

---

### P5-TC04: Feed URL Rejects javascript: Scheme

**Priority:** Critical  
**Method:** `test_feed_url_rejects_javascript()`

**Steps:**
```php
// 1. Submit feed URL: 'javascript:alert(1)'
// 2. Run sanitisation
$sanitised = esc_url_raw('javascript:alert(1)', ['http', 'https']);
```

**Expected Result:**
- URL is rejected (empty string or stripped)
- Setting not saved with invalid URL

---

### P5-TC05: Feed URL Rejects Non-HTTP Schemes

**Priority:** Critical  
**Method:** `test_feed_url_rejects_non_http()`

**Steps:**
```php
// Test various invalid schemes:
$invalid_urls = [
    'ftp://example.com/feed',
    'file:///etc/passwd',
    'data:text/html,<script>alert(1)</script>',
    'php://filter/read=convert.base64-encode/resource=wp-config.php',
];
```

**Expected Result:**
- All rejected by `esc_url_raw()` with `['http', 'https']` allowed protocols

---

### P5-TC06: Feed URL Accepts Valid HTTPS URL

**Priority:** High  
**Method:** `test_feed_url_accepts_valid_https()`

**Steps:**
```php
$url = 'https://687183.app.netsuite.com/app/reporting/webquery.nl?compid=687183';
$sanitised = esc_url_raw($url, ['http', 'https']);
```

**Expected Result:**
- URL preserved and saved correctly

---

### P5-TC07: Sync Interval Only Accepts Allowed Values

**Priority:** High  
**Method:** `test_sync_interval_whitelist()`

**Steps:**
```php
// Test allowed values: 30, 60, 120
// Test rejected values: 0, 1, 15, 45, 999, -1, 'abc'
```

**Expected Result:**
- Only 30, 60, 120 accepted
- Invalid values fall back to default (60)

---

### P5-TC08: Product Meta Box Saves Toggle Correctly

**Priority:** High  
**Method:** `test_product_meta_saves_toggle()`

**Steps:**
```php
// 1. Create product
// 2. Simulate saving product with _gco_ss_enabled = 'no'
// 3. Verify post meta
$enabled = get_post_meta($product_id, '_gco_ss_enabled', true);
```

**Expected Result:**
- `$enabled === 'no'`

---

### P5-TC09: Product Meta Toggle Defaults to Enabled

**Priority:** Medium  
**Method:** `test_product_meta_defaults_enabled()`

**Steps:**
```php
// 1. Create a new product (no meta set)
// 2. Check effective value
```

**Expected Result:**
- Default behaviour treats product as enabled (`'yes'`)

---

### P5-TC10: Log List Table Renders with Seeded Data

**Priority:** High  
**Method:** `test_log_list_table_renders()`

**Steps:**
```php
// 1. Insert 5 run rows into gco_ss_runs
// 2. Instantiate GCO_Stock_Sync_Log_List_Table
// 3. Call prepare_items() and display()
```

**Expected Result:**
- No PHP errors/warnings
- 5 rows displayed
- Columns: run time, supplier, status, rows fetched, products updated, message

---

### P5-TC11: Log List Table Paginates

**Priority:** Medium  
**Method:** `test_log_list_table_pagination()`

**Steps:**
```php
// 1. Insert 50 run rows
// 2. Set per_page = 20
// 3. Request page 2
```

**Expected Result:**
- Page 2 shows rows 21–40
- Pagination controls correct

---

### P5-TC12: Log Purge Deletes Only Old Rows

**Priority:** High  
**Method:** `test_log_purge_only_deletes_old()`

**Steps:**
```php
// 1. Insert runs with dates:
//    - 60 days ago (old)
//    - 31 days ago (old)
//    - 29 days ago (recent)
//    - today (recent)
// 2. Run purge with 30-day retention
// 3. Count remaining rows
```

**Expected Result:**
- 2 old runs deleted
- 2 recent runs preserved
- Associated item rows also cleaned up

---

### P5-TC13: Log Purge Respects Retention Window

**Priority:** High  
**Method:** `test_log_purge_retention_window()`

**Steps:**
```php
// 1. Set retention to 7 days
// 2. Insert runs at 6 days ago and 8 days ago
// 3. Run purge
```

**Expected Result:**
- 6-day-old run preserved
- 8-day-old run deleted

---

### P5-TC14: Manual Run Trigger Requires Nonce

**Priority:** Critical  
**Method:** `test_manual_run_requires_nonce()`

**Steps:**
```php
// 1. Admin user
// 2. AJAX request WITHOUT nonce
// 3. Call manual run handler
```

**Expected Result:**
- Request rejected (wp_die or 403)
- No sync executed

---

### P5-TC15: Manual Run Trigger Requires Capability

**Priority:** Critical  
**Method:** `test_manual_run_requires_capability()`

**Steps:**
```php
// 1. Subscriber user with valid nonce
// 2. Attempt manual run
```

**Expected Result:**
- Request denied
- No sync executed

---

### P5-TC16: Non-Privileged User Denied on Manual Run

**Priority:** Critical  
**Method:** `test_non_privileged_denied_manual_run()`

**Steps:**
```php
wp_set_current_user($this->factory->user->create(['role' => 'editor']));
// Attempt manual run with valid nonce
```

**Expected Result:**
- Denied (editor doesn't have `manage_woocommerce`)

---

### P5-TC17: Non-Privileged User Denied on Log Clear

**Priority:** Critical  
**Method:** `test_non_privileged_denied_log_clear()`

**Steps:**
```php
wp_set_current_user($this->factory->user->create(['role' => 'subscriber']));
// Attempt log clear
```

**Expected Result:**
- Denied
- Logs preserved

---

### P5-TC18: Admin Assets Only on Plugin Screens

**Priority:** Medium  
**Method:** `test_assets_only_on_plugin_screens()`

**Steps:**
```php
// 1. Simulate loading a non-plugin admin page (e.g. dashboard)
// 2. Check if plugin CSS/JS are enqueued
```

**Expected Result:**
- Plugin assets NOT enqueued on dashboard or other pages
- Assets only load on plugin's own settings/log pages

---

### P5-TC19: Status Panel Shows Last Run Info

**Priority:** Medium  
**Method:** `test_status_panel_shows_last_run()`

**Steps:**
```php
// 1. Insert a run row
// 2. Render settings page status panel
// 3. Check output
```

**Expected Result:**
- Shows last run time
- Shows last run result (success/failed)
- Shows next scheduled run time

---

### P5-TC20: WP-Cron Warning When DISABLE_WP_CRON

**Priority:** Medium  
**Method:** `test_wp_cron_warning_displayed()`

**Steps:**
```php
// 1. Define DISABLE_WP_CRON as true
// 2. Render settings page
// 3. Check for warning message
```

**Expected Result:**
- Warning message displayed explaining WP-Cron is disabled
- Real-cron alternative URL shown

---

### P5-TC21: Run Detail View Shows Items

**Priority:** Medium  
**Method:** `test_run_detail_shows_items()`

**Steps:**
```php
// 1. Insert a run with 3 item rows
// 2. Render run detail view
```

**Expected Result:**
- All 3 items displayed
- Each shows: SKU, product link, supplier qty, status change, action, note

---

### P5-TC22: Settings Change Reschedules Cron

**Priority:** High  
**Method:** `test_interval_change_reschedules_cron()`

**Steps:**
```php
// 1. Set interval to 60 min
// 2. Change interval to 30 min
// 3. Check cron schedule
```

**Expected Result:**
- Old schedule cleared
- New schedule active with 30-minute interval
