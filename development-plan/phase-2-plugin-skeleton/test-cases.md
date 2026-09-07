# Phase 2 — Test Cases (Plugin Skeleton & Lifecycle)

**Phase Type:** Automated Integration Tests  
**Test File:** `tests/integration/test-lifecycle.php`  
**Test Framework:** PHPUnit via WordPress test suite  
**Test Environment:** wp-env (Docker)

---

## Test Case Summary

| ID | Test | Priority | Type |
|----|------|----------|------|
| P2-TC01 | Activation creates `gco_ss_runs` table | Critical | Integration |
| P2-TC02 | Activation creates `gco_ss_items` table | Critical | Integration |
| P2-TC03 | Tables have correct columns and indices | Critical | Integration |
| P2-TC04 | Activation is idempotent | Critical | Integration |
| P2-TC05 | DB version option set on activation | High | Integration |
| P2-TC06 | Default options set on activation | High | Integration |
| P2-TC07 | Cron event scheduled on activation | High | Integration |
| P2-TC08 | Deactivation clears cron | Critical | Integration |
| P2-TC09 | Deactivation preserves tables | Critical | Integration |
| P2-TC10 | Deactivation preserves options | High | Integration |
| P2-TC11 | Uninstall (delete OFF) preserves tables | Critical | Integration |
| P2-TC12 | Uninstall (delete ON) drops tables | Critical | Integration |
| P2-TC13 | Uninstall (delete ON) removes options | High | Integration |
| P2-TC14 | Uninstall (delete ON) removes post meta | High | Integration |
| P2-TC15 | WooCommerce inactive shows admin notice | High | Integration |
| P2-TC16 | HPOS compatibility declared | Medium | Integration |
| P2-TC17 | ABSPATH guard on all files | Medium | Static |

---

## Detailed Test Cases

### P2-TC01: Activation Creates `gco_ss_runs` Table

**Priority:** Critical  
**Class:** `Test_Lifecycle`  
**Method:** `test_activation_creates_runs_table()`

**Preconditions:**
- Clean WordPress + WooCommerce install
- Plugin not previously activated

**Steps:**
```php
// 1. Activate plugin
do_action('activate_gco-stock-sync/gco-stock-sync.php');

// 2. Check table exists
global $wpdb;
$table = $wpdb->prefix . 'gco_ss_runs';
$result = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
```

**Expected Result:**
- `$result === $table` (table exists)

**Assertions:**
```php
$this->assertEquals($table, $result);
```

---

### P2-TC02: Activation Creates `gco_ss_items` Table

**Priority:** Critical  
**Method:** `test_activation_creates_items_table()`

**Steps:**
```php
do_action('activate_gco-stock-sync/gco-stock-sync.php');

global $wpdb;
$table = $wpdb->prefix . 'gco_ss_items';
$result = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
```

**Expected Result:**
- `$result === $table`

---

### P2-TC03: Tables Have Correct Columns and Indices

**Priority:** Critical  
**Method:** `test_tables_have_correct_schema()`

**Steps:**
```php
// 1. Activate plugin
// 2. DESCRIBE both tables
// 3. Verify columns match schema spec
```

**Expected Result — `gco_ss_runs`:**
- `id` — BIGINT UNSIGNED, AUTO_INCREMENT, PRIMARY KEY
- `supplier` — VARCHAR(64), NOT NULL
- `started_at` — DATETIME, NOT NULL
- `finished_at` — DATETIME, NULL
- `status` — VARCHAR(20), NOT NULL
- `rows_fetched` — INT UNSIGNED, DEFAULT 0
- `products_updated` — INT UNSIGNED, DEFAULT 0
- `message` — TEXT, NULL
- Index: `supplier_started` on (supplier, started_at)

**Expected Result — `gco_ss_items`:**
- `id` — BIGINT UNSIGNED, AUTO_INCREMENT, PRIMARY KEY
- `run_id` — BIGINT UNSIGNED, NOT NULL
- `sku` — VARCHAR(100), NOT NULL
- `product_id` — BIGINT UNSIGNED, NULL
- `supplier_qty` — INT, NULL
- `old_status` — VARCHAR(20), NULL
- `new_status` — VARCHAR(20), NULL
- `action` — VARCHAR(20), NOT NULL
- `note` — VARCHAR(255), NULL
- Index: `run_id` on (run_id)
- Index: `sku` on (sku)

---

### P2-TC04: Activation Is Idempotent

**Priority:** Critical  
**Method:** `test_activation_is_idempotent()`

**Steps:**
```php
// 1. Activate plugin (first time)
GCO_Stock_Sync_Activator::activate();

// 2. Insert a test row into gco_ss_runs
$wpdb->insert($table, ['supplier' => 'test', 'started_at' => current_time('mysql'), 'status' => 'success']);
$count_before = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

// 3. Activate plugin again (second time)
GCO_Stock_Sync_Activator::activate();

// 4. Verify: no errors, test row still exists, schema unchanged
$count_after = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
```

**Expected Result:**
- No PHP errors or warnings
- `$count_after === $count_before` (data preserved)
- Table schema unchanged

---

### P2-TC05: DB Version Option Set on Activation

**Priority:** High  
**Method:** `test_db_version_set_on_activation()`

**Steps:**
```php
GCO_Stock_Sync_Activator::activate();
$version = get_option('gco_stock_sync_db_version');
```

**Expected Result:**
- `$version` is a non-empty string (e.g. `'1.0.0'`)

---

### P2-TC06: Default Options Set on Activation

**Priority:** High  
**Method:** `test_default_options_set_on_activation()`

**Steps:**
```php
GCO_Stock_Sync_Activator::activate();
$settings = get_option('gco_stock_sync_settings');
```

**Expected Result:**
- `$settings['enabled'] === false`
- `$settings['sync_interval'] === 60`
- `$settings['delete_data_uninstall'] === false`

---

### P2-TC07: Cron Event Scheduled on Activation

**Priority:** High  
**Method:** `test_cron_scheduled_on_activation()`

**Steps:**
```php
GCO_Stock_Sync_Activator::activate();
$next = wp_next_scheduled('gco_stock_sync_cron');
```

**Expected Result:**
- `$next` is a positive integer (Unix timestamp)
- The custom interval is registered in `cron_schedules`

---

### P2-TC08: Deactivation Clears Cron

**Priority:** Critical  
**Method:** `test_deactivation_clears_cron()`

**Steps:**
```php
// 1. Activate to schedule cron
GCO_Stock_Sync_Activator::activate();
$this->assertNotFalse(wp_next_scheduled('gco_stock_sync_cron'));

// 2. Deactivate
GCO_Stock_Sync_Deactivator::deactivate();
$next = wp_next_scheduled('gco_stock_sync_cron');
```

**Expected Result:**
- `$next === false` (cron cleared)

---

### P2-TC09: Deactivation Preserves Tables

**Priority:** Critical  
**Method:** `test_deactivation_preserves_tables()`

**Steps:**
```php
// 1. Activate and insert data
GCO_Stock_Sync_Activator::activate();
$wpdb->insert($runs_table, [...]);

// 2. Deactivate
GCO_Stock_Sync_Deactivator::deactivate();

// 3. Check tables and data still exist
```

**Expected Result:**
- Both tables still exist
- Inserted data is intact

---

### P2-TC10: Deactivation Preserves Options

**Priority:** High  
**Method:** `test_deactivation_preserves_options()`

**Steps:**
```php
GCO_Stock_Sync_Activator::activate();
GCO_Stock_Sync_Deactivator::deactivate();
$settings = get_option('gco_stock_sync_settings');
```

**Expected Result:**
- Options are still present and unchanged

---

### P2-TC11: Uninstall with Delete OFF Preserves Tables

**Priority:** Critical  
**Method:** `test_uninstall_delete_off_preserves_data()`

**Steps:**
```php
// 1. Activate and set delete_data_uninstall = false
GCO_Stock_Sync_Activator::activate();
$settings = get_option('gco_stock_sync_settings');
$settings['delete_data_uninstall'] = false;
update_option('gco_stock_sync_settings', $settings);

// 2. Simulate uninstall logic
// 3. Check tables still exist
```

**Expected Result:**
- Both tables intact
- Options intact
- Post meta intact

---

### P2-TC12: Uninstall with Delete ON Drops Tables

**Priority:** Critical  
**Method:** `test_uninstall_delete_on_drops_tables()`

**Steps:**
```php
// 1. Activate, set delete_data_uninstall = true
// 2. Simulate uninstall logic
// 3. SHOW TABLES LIKE for both tables
```

**Expected Result:**
- `gco_ss_runs` table does NOT exist
- `gco_ss_items` table does NOT exist

---

### P2-TC13: Uninstall with Delete ON Removes Options

**Priority:** High  
**Method:** `test_uninstall_delete_on_removes_options()`

**Steps:**
```php
// 1. Activate, set delete = true, simulate uninstall
// 2. Check options
```

**Expected Result:**
- `get_option('gco_stock_sync_settings')` returns `false`
- `get_option('gco_stock_sync_db_version')` returns `false`

---

### P2-TC14: Uninstall with Delete ON Removes Post Meta

**Priority:** High  
**Method:** `test_uninstall_delete_on_removes_post_meta()`

**Steps:**
```php
// 1. Create a test product with _gco_ss_* meta
// 2. Activate, set delete = true, simulate uninstall
// 3. Check post meta
```

**Expected Result:**
- All `_gco_ss_enabled`, `_gco_ss_supplier`, `_gco_ss_last_qty`, `_gco_ss_last_sync` meta deleted

---

### P2-TC15: WooCommerce Inactive Shows Admin Notice

**Priority:** High  
**Method:** `test_woo_inactive_shows_notice()`

**Steps:**
```php
// 1. Deactivate WooCommerce
// 2. Load the plugin
// 3. Check for admin notice hook
```

**Expected Result:**
- Admin notice is displayed warning that WooCommerce is required
- Plugin hooks are not registered (sync, admin pages, etc.)

---

### P2-TC16: HPOS Compatibility Declared

**Priority:** Medium  
**Method:** `test_hpos_compatibility_declared()`

**Steps:**
```php
// 1. Check that 'before_woocommerce_init' hook is registered
// 2. Verify FeaturesUtil::declare_compatibility is called
```

**Expected Result:**
- HPOS compatibility declared for the plugin file

---

### P2-TC17: ABSPATH Guard on All Files

**Priority:** Medium  
**Type:** Static analysis

**Steps:**
1. Scan all `.php` files in the plugin directory
2. Check each file starts with `defined('ABSPATH') || exit;` (or `die()`)
3. Exception: `uninstall.php` uses `WP_UNINSTALL_PLUGIN` guard instead

**Expected Result:**
- Every PHP file is guarded against direct access
