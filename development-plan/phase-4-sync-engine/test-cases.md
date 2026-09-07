# Phase 4 — Test Cases (Sync Engine)

**Phase Type:** Automated Integration Tests (CRITICAL)  
**Test Files:** `tests/integration/test-sync-runner.php`, `tests/integration/test-product-matcher.php`  
**Test Framework:** PHPUnit via WordPress test suite  
**Test Environment:** wp-env with WooCommerce activated

> [!CAUTION]
> These are the tests that protect the client's storefront. Every test here must pass before this phase is considered complete. No exceptions.

---

## Test Case Summary

| ID | Test | Priority | Type |
|----|------|----------|------|
| P4-TC01 | Fetch failure leaves ALL product stock untouched (WP_Error) | **CRITICAL** | Integration |
| P4-TC02 | Fetch failure leaves ALL product stock untouched (HTTP 500) | **CRITICAL** | Integration |
| P4-TC03 | Fetch failure leaves ALL product stock untouched (empty body) | **CRITICAL** | Integration |
| P4-TC04 | Fetch failure leaves ALL product stock untouched (garbage body) | **CRITICAL** | Integration |
| P4-TC05 | Fetch failure leaves ALL product stock untouched (suspiciously empty) | **CRITICAL** | Integration |
| P4-TC06 | qty > 0 → instock | **CRITICAL** | Integration |
| P4-TC07 | qty = 0 → outofstock | **CRITICAL** | Integration |
| P4-TC08 | qty = 1 → instock (boundary, no buffer) | **CRITICAL** | Integration |
| P4-TC09 | Product with _gco_ss_enabled=no is never touched | **CRITICAL** | Integration |
| P4-TC10 | SKU in feed but not on site → recorded as unmatched | High | Integration |
| P4-TC11 | Product on site but not in feed → untouched | **CRITICAL** | Integration |
| P4-TC12 | stock_quantity is NEVER written | **CRITICAL** | Integration |
| P4-TC13 | Already-correct status → recorded unchanged, no save() | High | Integration |
| P4-TC14 | Variation SKU resolves and updates variation | High | Integration |
| P4-TC15 | Lock: second concurrent run exits as 'skipped' | High | Integration |
| P4-TC16 | Run row and item rows written with correct counts | High | Integration |
| P4-TC17 | Exception on one product doesn't abort remaining | **CRITICAL** | Integration |
| P4-TC18 | manage_stock ON product triggers warning log | Medium | Integration |
| P4-TC19 | SKU matching is case-insensitive | High | Integration |
| P4-TC20 | SKU matching trims whitespace | High | Integration |
| P4-TC21 | Normalised match logs a warning | Medium | Integration |
| P4-TC22 | Cron interval registration | High | Integration |
| P4-TC23 | Lock released even on fatal/exception | High | Integration |
| P4-TC24 | Post meta updated on unchanged status | Medium | Integration |
| P4-TC25 | qty 5 (instock) → qty 0 (outofstock) transition | **CRITICAL** | Integration |
| P4-TC26 | qty 0 (outofstock) → qty 3 (instock) transition | **CRITICAL** | Integration |

---

## Test Helper: Product Factory

```php
/**
 * Create a WooCommerce product with specific attributes for testing.
 */
private function create_test_product($sku, $stock_status = 'instock', $enabled = 'yes') {
    $product = new WC_Product_Simple();
    $product->set_sku($sku);
    $product->set_stock_status($stock_status);
    $product->set_manage_stock(false);
    $product->save();

    update_post_meta($product->get_id(), '_gco_ss_enabled', $enabled);
    update_post_meta($product->get_id(), '_gco_ss_supplier', 'highland_outdoors');

    return $product;
}
```

---

## Detailed Test Cases

### P4-TC01: Fetch Failure (WP_Error) Leaves ALL Stock Untouched

**Priority:** CRITICAL — This is the #1 safety test  
**Method:** `test_wp_error_leaves_all_stock_untouched()`

**Preconditions:**
- 5 products seeded as 'instock' with known SKUs
- HTTP mocked to return `WP_Error`

**Steps:**
```php
// 1. Create 5 products, all instock
$products = [];
foreach (['SKU-A', 'SKU-B', 'SKU-C', 'SKU-D', 'SKU-E'] as $sku) {
    $products[$sku] = $this->create_test_product($sku, 'instock');
}

// 2. Mock HTTP to return WP_Error
add_filter('pre_http_request', function() {
    return new WP_Error('http_request_failed', 'Connection timed out');
});

// 3. Run sync
$runner = new GCO_Stock_Sync_Sync_Runner();
$runner->run('highland_outdoors');

// 4. Reload every product and check status
foreach ($products as $sku => $product) {
    $refreshed = wc_get_product($product->get_id());
    $this->assertEquals('instock', $refreshed->get_stock_status(),
        "Product {$sku} was modified during a failed sync!");
}
```

**Expected Result:**
- ALL 5 products remain 'instock'
- Run row has status = 'failed'
- Zero item rows recorded (no product processing occurred)

---

### P4-TC02: Fetch Failure (HTTP 500) Leaves ALL Stock Untouched

**Priority:** CRITICAL  
**Method:** `test_http_500_leaves_all_stock_untouched()`

**Steps:** Same as P4-TC01 but mock returns HTTP 500 instead of WP_Error.

**Expected Result:** Identical — zero products touched.

---

### P4-TC03: Fetch Failure (Empty Body) Leaves ALL Stock Untouched

**Priority:** CRITICAL  
**Method:** `test_empty_body_leaves_all_stock_untouched()`

**Steps:** Mock returns HTTP 200 with empty body.

**Expected Result:** Identical — zero products touched.

---

### P4-TC04: Fetch Failure (Garbage Body) Leaves ALL Stock Untouched

**Priority:** CRITICAL  
**Method:** `test_garbage_body_leaves_all_stock_untouched()`

**Steps:** Mock returns `feed-garbage.html` fixture.

**Expected Result:** Identical — zero products touched.

---

### P4-TC05: Fetch Failure (Suspiciously Empty) Leaves ALL Stock Untouched

**Priority:** CRITICAL  
**Method:** `test_suspiciously_empty_leaves_all_stock_untouched()`

**Steps:** Mock returns `feed-empty.html` (valid structure, zero product rows).

**Expected Result:** Identical — zero products touched. This is the critical case where a broken feed could be misinterpreted as "everything is out of stock."

---

### P4-TC06: qty > 0 Sets Product Instock

**Priority:** CRITICAL  
**Method:** `test_qty_positive_sets_instock()`

**Steps:**
```php
// 1. Create product with SKU-A, status = 'outofstock'
$product = $this->create_test_product('SKU-A', 'outofstock');

// 2. Mock feed with SKU-A qty = 5
// 3. Run sync
// 4. Reload product
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- `$refreshed->get_stock_status() === 'instock'`
- Item row: action = 'updated', old_status = 'outofstock', new_status = 'instock'

---

### P4-TC07: qty = 0 Sets Product Outofstock

**Priority:** CRITICAL  
**Method:** `test_qty_zero_sets_outofstock()`

**Steps:**
```php
// 1. Create product with SKU-B, status = 'instock'
$product = $this->create_test_product('SKU-B', 'instock');

// 2. Mock feed with SKU-B qty = 0
// 3. Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- `$refreshed->get_stock_status() === 'outofstock'`
- Item row: action = 'updated', old_status = 'instock', new_status = 'outofstock'

---

### P4-TC08: qty = 1 Sets Instock (Boundary — No Buffer)

**Priority:** CRITICAL  
**Method:** `test_qty_one_is_instock_no_buffer()`

**Steps:**
```php
$product = $this->create_test_product('SKU-C', 'outofstock');
// Mock feed: SKU-C qty = 1
// Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- `$refreshed->get_stock_status() === 'instock'`
- The threshold is exactly 0 — qty of 1 means instock, with NO safety buffer

---

### P4-TC09: Disabled Product Never Touched

**Priority:** CRITICAL  
**Method:** `test_disabled_product_never_touched()`

**Steps:**
```php
// 1. Create product with _gco_ss_enabled = 'no', status = 'instock'
$product = $this->create_test_product('SKU-D', 'instock', 'no');

// 2. Mock feed with SKU-D qty = 0 (would normally mark outofstock)
// 3. Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- `$refreshed->get_stock_status() === 'instock'` (unchanged!)
- Item row: action = 'skipped'
- `_gco_ss_last_qty` NOT updated
- `_gco_ss_last_sync` NOT updated

---

### P4-TC10: Unmatched SKU Recorded, Run Succeeds

**Priority:** High  
**Method:** `test_unmatched_sku_recorded_run_succeeds()`

**Steps:**
```php
// 1. Mock feed with SKU 'DOES-NOT-EXIST' qty = 5
// 2. No product with that SKU exists in WooCommerce
// 3. Also include a valid SKU that does exist
// 4. Run sync
```

**Expected Result:**
- Item row for 'DOES-NOT-EXIST': action = 'unmatched', product_id = null
- Run still completes with status = 'success'
- Valid products still processed correctly

---

### P4-TC11: Product on Site but NOT in Feed → Untouched

**Priority:** CRITICAL  
**Method:** `test_product_not_in_feed_untouched()`

**Steps:**
```php
// 1. Create product SKU-E, instock
$product = $this->create_test_product('SKU-E', 'instock');

// 2. Mock feed that does NOT contain SKU-E
// 3. Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- `$refreshed->get_stock_status() === 'instock'`
- No item row for SKU-E
- Absence from feed ≠ out of stock

---

### P4-TC12: stock_quantity Is NEVER Written

**Priority:** CRITICAL  
**Method:** `test_stock_quantity_never_written()`

**Steps:**
```php
// 1. Create product, set stock_quantity to a specific value (e.g. 42)
$product = $this->create_test_product('SKU-F', 'outofstock');
update_post_meta($product->get_id(), '_stock', 42);

// 2. Mock feed: SKU-F qty = 5 (triggers instock update)
// 3. Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- `get_post_meta($product->get_id(), '_stock', true)` is still `42`
- Stock status changed but quantity untouched
- Plugin NEVER calls `set_stock_quantity()` or writes `_stock` meta

---

### P4-TC13: Already-Correct Status → Unchanged, No Save

**Priority:** High  
**Method:** `test_already_correct_status_unchanged()`

**Steps:**
```php
// 1. Create product SKU-G, instock
$product = $this->create_test_product('SKU-G', 'instock');

// 2. Mock feed: SKU-G qty = 5 (already instock)
// 3. Run sync (with action counter or hook to detect save())
```

**Expected Result:**
- Item row: action = 'unchanged'
- `_gco_ss_last_qty` IS updated (for diagnostics)
- `_gco_ss_last_sync` IS updated
- No unnecessary `$product->save()` call (status didn't change)

---

### P4-TC14: Variation SKU Updates Variation, Not Parent

**Priority:** High  
**Method:** `test_variation_sku_updates_variation()`

**Steps:**
```php
// 1. Create a variable product
$parent = new WC_Product_Variable();
$parent->set_name('Test Variable Product');
$parent->save();

// 2. Create a variation with a specific SKU
$variation = new WC_Product_Variation();
$variation->set_parent_id($parent->get_id());
$variation->set_sku('VAR-SKU-1');
$variation->set_stock_status('outofstock');
$variation->save();

// 3. Mock feed: VAR-SKU-1 qty = 3
// 4. Run sync
$refreshed = wc_get_product($variation->get_id());
```

**Expected Result:**
- Variation status = 'instock'
- Parent product NOT modified

---

### P4-TC15: Lock Prevents Concurrent Run

**Priority:** High  
**Method:** `test_lock_prevents_concurrent_run()`

**Steps:**
```php
// 1. Set the lock transient manually
set_transient('gco_stock_sync_lock', time(), 900);

// 2. Attempt to run sync
$runner = new GCO_Stock_Sync_Sync_Runner();
$result = $runner->run('highland_outdoors');
```

**Expected Result:**
- Run aborts immediately
- Run row: status = 'skipped'
- No products processed
- Lock remains intact

---

### P4-TC16: Run Row and Item Rows Written Correctly

**Priority:** High  
**Method:** `test_run_and_item_rows_correct()`

**Steps:**
```php
// 1. Create 3 products: 1 needs update, 1 unchanged, 1 disabled
// 2. Mock feed with corresponding SKUs
// 3. Run sync
// 4. Query gco_ss_runs and gco_ss_items tables
```

**Expected Result:**
- 1 run row with correct: supplier, started_at, finished_at, status='success', rows_fetched, products_updated
- 3 item rows with correct: run_id, sku, product_id, supplier_qty, old/new status, action

---

### P4-TC17: Exception on One Product Doesn't Abort Others

**Priority:** CRITICAL  
**Method:** `test_exception_on_product_doesnt_abort_run()`

**Steps:**
```php
// 1. Create 3 products: SKU-A, SKU-B, SKU-C
// 2. Mock the product matcher to throw exception for SKU-B
// 3. Run sync
```

**Expected Result:**
- SKU-A processed successfully
- SKU-B logged with error note
- SKU-C processed successfully (NOT aborted)
- Run status = 'success' or 'partial' (not 'failed')

---

### P4-TC18: manage_stock ON Triggers Warning

**Priority:** Medium  
**Method:** `test_manage_stock_on_triggers_warning()`

**Steps:**
```php
// 1. Create product with manage_stock = true
$product = $this->create_test_product('SKU-MANAGED', 'instock');
$wc_product = wc_get_product($product->get_id());
$wc_product->set_manage_stock(true);
$wc_product->save();

// 2. Run sync
// 3. Check item note
```

**Expected Result:**
- Item row has a warning note about manage_stock being enabled
- Product is still updated (or intentionally skipped with documented reason)

---

### P4-TC19: SKU Matching Is Case-Insensitive

**Priority:** High  
**Method:** `test_sku_matching_case_insensitive()`

**Steps:**
```php
// 1. Create product with SKU = 'ABC-123'
// 2. Feed contains sku = 'abc-123' (lowercase)
// 3. Run sync
```

**Expected Result:**
- Product matched and processed
- Item row has correct product_id

---

### P4-TC20: SKU Matching Trims Whitespace

**Priority:** High  
**Method:** `test_sku_matching_trims_whitespace()`

**Steps:**
```php
// 1. Create product with SKU = 'XYZ-789'
// 2. Feed contains sku = '  XYZ-789  ' (padded)
// 3. Run sync
```

**Expected Result:**
- Product matched after trimming

---

### P4-TC21: Normalised Match Logs Warning

**Priority:** Medium  
**Method:** `test_normalised_match_logs_warning()`

**Steps:**
```php
// 1. Match only succeeds after case/whitespace normalisation
// 2. Check item note
```

**Expected Result:**
- Item note contains a message suggesting the client clean up their SKU data

---

### P4-TC22: Cron Interval Registration

**Priority:** High  
**Method:** `test_cron_interval_registered()`

**Steps:**
```php
$schedules = wp_get_schedules();
```

**Expected Result:**
- Custom interval present in `$schedules`
- Interval matches the configured setting (30/60/120 minutes)

---

### P4-TC23: Lock Released on Exception

**Priority:** High  
**Method:** `test_lock_released_on_exception()`

**Steps:**
```php
// 1. Force an exception during sync
// 2. Check lock transient after
$lock = get_transient('gco_stock_sync_lock');
```

**Expected Result:**
- Lock transient is cleared (or expired)
- Next sync can proceed

---

### P4-TC24: Post Meta Updated on Unchanged Status

**Priority:** Medium  
**Method:** `test_post_meta_updated_on_unchanged()`

**Steps:**
```php
// 1. Product already instock, feed says instock (unchanged)
// 2. Run sync
// 3. Check post meta
```

**Expected Result:**
- `_gco_ss_last_qty` updated to feed qty
- `_gco_ss_last_sync` updated to current time

---

### P4-TC25: Instock → Outofstock Transition

**Priority:** CRITICAL  
**Method:** `test_instock_to_outofstock_transition()`

**Steps:**
```php
$product = $this->create_test_product('TRANS-1', 'instock');
// Feed: TRANS-1 qty = 0
// Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- Status changed from 'instock' to 'outofstock'
- Item row: action='updated', old_status='instock', new_status='outofstock'

---

### P4-TC26: Outofstock → Instock Transition

**Priority:** CRITICAL  
**Method:** `test_outofstock_to_instock_transition()`

**Steps:**
```php
$product = $this->create_test_product('TRANS-2', 'outofstock');
// Feed: TRANS-2 qty = 3
// Run sync
$refreshed = wc_get_product($product->get_id());
```

**Expected Result:**
- Status changed from 'outofstock' to 'instock'
- Item row: action='updated', old_status='outofstock', new_status='instock'
