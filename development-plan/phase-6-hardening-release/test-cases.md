# Phase 6 — Test Cases (Hardening, i18n, Release)

**Phase Type:** Automated Integration + Static Analysis  
**Test Files:** `tests/integration/test-failure-notifier.php`, `tests/integration/test-cli.php`, `tests/integration/test-i18n.php`  
**Test Framework:** PHPUnit via WordPress test suite

---

## Test Case Summary

| ID | Test | Priority | Type |
|----|------|----------|------|
| P6-TC01 | All user-facing strings are translatable | High | Static |
| P6-TC02 | POT file generated and valid | High | Static |
| P6-TC03 | Text domain matches plugin slug | High | Static |
| P6-TC04 | Email sent after N consecutive failures | Critical | Integration |
| P6-TC05 | Email NOT sent on first failure | High | Integration |
| P6-TC06 | Email deduplication (not sent every hour) | High | Integration |
| P6-TC07 | Counter resets on successful run | High | Integration |
| P6-TC08 | Test connection button returns row count | High | Integration |
| P6-TC09 | Test connection doesn't write any data | Critical | Integration |
| P6-TC10 | Test connection requires nonce + capability | Critical | Integration |
| P6-TC11 | WP-CLI run command executes sync | High | Integration |
| P6-TC12 | WP-CLI dry-run doesn't modify stock | Critical | Integration |
| P6-TC13 | WP-CLI force bypasses lock | Medium | Integration |
| P6-TC14 | WP-CLI output format correct | Medium | Integration |
| P6-TC15 | Release zip excludes dev files | High | Build |
| P6-TC16 | No error_log calls in shipped code | High | Static |
| P6-TC17 | PHPCS passes WordPress standard | High | Static |
| P6-TC18 | Version numbers consistent across files | Medium | Static |

---

## Detailed Test Cases

### P6-TC01: All User-Facing Strings Translatable

**Priority:** High  
**Type:** Static analysis  
**Method:** `test_all_strings_translatable()`

**Steps:**
1. Scan all PHP files for user-facing strings (echoed, wp_die messages, admin notices)
2. Verify each is wrapped in `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, or `_n()`
3. Verify text domain is `'gco-stock-sync'` in every call

**Expected Result:**
- Zero bare user-facing strings
- All use the correct text domain

---

### P6-TC02: POT File Generated and Valid

**Priority:** High  
**Type:** Build verification

**Steps:**
```bash
wp i18n make-pot . languages/gco-stock-sync.pot --slug=gco-stock-sync
```

**Expected Result:**
- `languages/gco-stock-sync.pot` exists
- File is valid POT format
- Contains entries for all translatable strings

---

### P6-TC03: Text Domain Matches Plugin Slug

**Priority:** High  
**Type:** Static analysis

**Steps:**
1. Check plugin header `Text Domain: gco-stock-sync`
2. Grep all `__()` / `_e()` calls for text domain argument
3. Verify none use a different domain

**Expected Result:**
- All translation function calls use `'gco-stock-sync'`

---

### P6-TC04: Email Sent After N Consecutive Failures

**Priority:** Critical  
**Method:** `test_email_after_consecutive_failures()`

**Steps:**
```php
// 1. Mock the mailer to capture emails
$emails_sent = [];
add_filter('wp_mail', function($args) use (&$emails_sent) {
    $emails_sent[] = $args;
    return $args;
});

// 2. Simulate 3 consecutive failed runs
for ($i = 0; $i < 3; $i++) {
    // Force fetch failure
    $runner->run('highland_outdoors');
}

// 3. Check emails
```

**Expected Result:**
- Exactly 1 email sent (after the 3rd failure)
- Email goes to site admin
- Subject mentions "stock sync" and "failed"
- Body includes error details

---

### P6-TC05: Email NOT Sent on First Failure

**Priority:** High  
**Method:** `test_no_email_on_first_failure()`

**Steps:**
```php
// 1. Mock mailer
// 2. Simulate 1 failed run
// 3. Check emails
```

**Expected Result:**
- Zero emails sent
- Consecutive failure counter = 1

---

### P6-TC06: Email Deduplication

**Priority:** High  
**Method:** `test_email_deduplication()`

**Steps:**
```php
// 1. Trigger 3 failures (email sent)
// 2. Trigger 3 more failures
// 3. Check email count
```

**Expected Result:**
- Only 1 email total (not 2)
- Dedup window prevents repeated notifications
- Only a new email after a successful run resets the counter and then failures recur

---

### P6-TC07: Counter Resets on Successful Run

**Priority:** High  
**Method:** `test_counter_resets_on_success()`

**Steps:**
```php
// 1. Simulate 2 failures (counter = 2)
// 2. Simulate 1 success (counter should reset to 0)
// 3. Simulate 2 more failures
// 4. Check: no email (only 2 failures since reset)
```

**Expected Result:**
- Counter = 0 after successful run
- Email threshold resets

---

### P6-TC08: Test Connection Returns Row Count

**Priority:** High  
**Method:** `test_connection_test_returns_count()`

**Steps:**
```php
// 1. Mock feed with valid data
// 2. Call test connection handler
// 3. Check response
```

**Expected Result:**
- Response includes row count from the feed
- Response indicates success/failure
- No side effects (no product updates, no run rows)

---

### P6-TC09: Test Connection Doesn't Write Data

**Priority:** Critical  
**Method:** `test_connection_test_no_side_effects()`

**Steps:**
```php
// 1. Count rows in gco_ss_runs before
$before = $wpdb->get_var("SELECT COUNT(*) FROM {$runs_table}");

// 2. Run test connection
// 3. Count rows after
$after = $wpdb->get_var("SELECT COUNT(*) FROM {$runs_table}");

// 4. Check product stock statuses unchanged
```

**Expected Result:**
- `$before === $after` (no run rows added)
- No product stock status changed
- No post meta modified

---

### P6-TC10: Test Connection Requires Security

**Priority:** Critical  
**Method:** `test_connection_test_security()`

**Steps:**
```php
// Test 1: No nonce → denied
// Test 2: Invalid nonce → denied
// Test 3: Subscriber user with valid nonce → denied
// Test 4: Admin with valid nonce → allowed
```

---

### P6-TC11: WP-CLI Run Command Executes Sync

**Priority:** High  
**Method:** `test_cli_run_executes_sync()`

**Steps:**
```php
// 1. Register CLI command
// 2. Invoke: wp gco-stock-sync run
// 3. Check sync executed
```

**Expected Result:**
- Sync runs to completion
- Run row created in database
- Output shows summary

---

### P6-TC12: WP-CLI Dry Run Doesn't Modify Stock

**Priority:** Critical  
**Method:** `test_cli_dry_run_no_modifications()`

**Steps:**
```php
// 1. Create products
// 2. Invoke: wp gco-stock-sync run --dry-run
// 3. Check products unchanged
```

**Expected Result:**
- Feed fetched and parsed
- SKUs matched
- But NO stock status changes applied
- Output shows what WOULD happen

---

### P6-TC13: WP-CLI Force Bypasses Lock

**Priority:** Medium  
**Method:** `test_cli_force_bypasses_lock()`

**Steps:**
```php
// 1. Set lock transient
set_transient('gco_stock_sync_lock', time(), 900);

// 2. Run: wp gco-stock-sync run --force
```

**Expected Result:**
- Sync runs despite lock being set
- Useful for debugging

---

### P6-TC14: WP-CLI Output Format

**Priority:** Medium  
**Method:** `test_cli_output_format()`

**Steps:**
```php
// 1. Run CLI command with products
// 2. Capture output
```

**Expected Result:**
- Table output with columns: SKU, Action, Old Status, New Status
- Summary line with counts

---

### P6-TC15: Release Zip Excludes Dev Files

**Priority:** High  
**Type:** Build verification

**Steps:**
```bash
# Build the zip
# List contents
unzip -l gco-stock-sync.zip
```

**Expected Result:**
Zip does NOT contain:
- `tests/`
- `node_modules/`
- `.git/`
- `development-plan/`
- `research/`
- `.github/`
- `phpunit.xml`
- `composer.lock`
- `package-lock.json`
- `.env`

---

### P6-TC16: No error_log in Shipped Code

**Priority:** High  
**Type:** Static analysis

**Steps:**
```bash
grep -r 'error_log' --include='*.php' . | grep -v 'tests/' | grep -v 'vendor/'
```

**Expected Result:**
- Zero matches (no debug logging left in production code)

---

### P6-TC17: PHPCS WordPress Standard Passes

**Priority:** High  
**Type:** Static analysis

**Steps:**
```bash
phpcs --standard=WordPress --extensions=php --ignore=tests/,vendor/ .
```

**Expected Result:**
- Zero errors
- Warnings acceptable if documented

---

### P6-TC18: Version Numbers Consistent

**Priority:** Medium  
**Type:** Static analysis

**Steps:**
1. Check `gco-stock-sync.php` plugin header version
2. Check `readme.txt` stable tag
3. Check any `GCO_STOCK_SYNC_VERSION` constant
4. Check `package.json` version (if exists)

**Expected Result:**
- All version strings match (e.g. all `1.0.0`)
