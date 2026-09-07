# Phase 3 — Test Cases (Supplier Abstraction + Highland Outdoors Connector)

**Phase Type:** Automated Integration Tests  
**Test File:** `tests/integration/test-supplier-highland.php`  
**Test Framework:** PHPUnit via WordPress test suite  
**HTTP Mocking:** `pre_http_request` filter to serve fixture files

---

## Test Case Summary

| ID | Test | Priority | Type |
|----|------|----------|------|
| P3-TC01 | Valid feed → correct SKU count | Critical | Integration |
| P3-TC02 | Valid feed → qty values correct | Critical | Integration |
| P3-TC03 | Valid feed → section-header rows excluded | Critical | Integration |
| P3-TC04 | Empty feed → ok=false, suspiciously_empty | Critical | Integration |
| P3-TC05 | Garbage feed → ok=false, parse_failed | Critical | Integration |
| P3-TC06 | WP_Error from HTTP → ok=false, fetch_failed | Critical | Integration |
| P3-TC07 | HTTP 500 → ok=false | Critical | Integration |
| P3-TC08 | HTTP 200 empty body → ok=false | Critical | Integration |
| P3-TC09 | Qty normalisation: "1,234" → 1234 | High | Unit |
| P3-TC10 | Qty normalisation: "-5" → 0 | High | Unit |
| P3-TC11 | Qty normalisation: "" → row skipped | High | Unit |
| P3-TC12 | Supplier discoverable via filter | High | Integration |
| P3-TC13 | Feed URL stored as setting, not hardcoded | Medium | Integration |
| P3-TC14 | Below minimum rows → suspiciously_empty | High | Integration |
| P3-TC15 | Fetch result structure validated | Medium | Unit |
| P3-TC16 | Failed fetch stores debug transient | Medium | Integration |

---

## HTTP Mock Setup

All tests use the `pre_http_request` filter to intercept HTTP calls and serve fixture files:

```php
add_filter('pre_http_request', function($preempt, $args, $url) {
    if (strpos($url, 'netsuite.com') !== false) {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => file_get_contents(__DIR__ . '/../fixtures/feed-valid.html'),
            'headers'  => ['content-type' => 'text/html'],
        ];
    }
    return $preempt;
}, 10, 3);
```

---

## Detailed Test Cases

### P3-TC01: Valid Feed Returns Correct SKU Count

**Priority:** Critical  
**Method:** `test_valid_feed_returns_correct_sku_count()`

**Preconditions:**
- `feed-valid.html` fixture loaded via `pre_http_request`
- Supplier instance created with valid settings

**Steps:**
```php
// 1. Mock HTTP to return feed-valid.html
// 2. Create Highland Outdoors supplier instance
$supplier = new GCO_Stock_Sync_Highland_Outdoors();

// 3. Fetch
$result = $supplier->fetch();

// 4. Count items
$sku_count = count($result->items);
```

**Expected Result:**
- `$result->ok === true`
- `$sku_count` matches the known product row count from the fixture
- No section-header rows included in the count

**Assertions:**
```php
$this->assertTrue($result->ok);
$this->assertEquals(EXPECTED_PRODUCT_COUNT, count($result->items));
```

---

### P3-TC02: Valid Feed Returns Correct Qty Values

**Priority:** Critical  
**Method:** `test_valid_feed_qty_values_match()`

**Steps:**
```php
// 1. Mock HTTP to return feed-valid.html
// 2. Fetch
$result = $supplier->fetch();

// 3. Spot-check specific SKUs against known fixture data
$items_by_sku = array_column($result->items, null, 'sku');
```

**Expected Result:**
- Specific SKUs have the correct qty values from the fixture
- Qty is always an integer, never a string
- Each item has: `sku`, `qty`, `name`, `internal_id`

**Assertions:**
```php
$this->assertIsInt($items_by_sku['KNOWN-SKU']['qty']);
$this->assertEquals(EXPECTED_QTY, $items_by_sku['KNOWN-SKU']['qty']);
```

---

### P3-TC03: Section-Header Rows Are Excluded

**Priority:** Critical  
**Method:** `test_section_header_rows_excluded()`

**Steps:**
```php
// 1. Mock HTTP with feed-valid.html (which includes section headers)
// 2. Fetch
$result = $supplier->fetch();

// 3. Check no items have known section-header names as SKUs
$skus = array_column($result->items, 'sku');
```

**Expected Result:**
- No items with SKU = "Boston Security", "Cabinets", "Buffalo River", or other section headers
- `$result->raw_row_count > count($result->items)` (raw includes headers, items don't)

**Assertions:**
```php
$this->assertNotContains('Boston Security', $skus);
$this->assertNotContains('Cabinets', $skus);
$this->assertGreaterThan(count($result->items), $result->raw_row_count);
```

---

### P3-TC04: Empty Feed Returns Error

**Priority:** Critical  
**Method:** `test_empty_feed_returns_suspiciously_empty()`

**Steps:**
```php
// 1. Mock HTTP to return feed-empty.html
// 2. Fetch
$result = $supplier->fetch();
```

**Expected Result:**
- `$result->ok === false`
- `$result->error_code === 'suspiciously_empty'`
- `$result->items` is empty

**Assertions:**
```php
$this->assertFalse($result->ok);
$this->assertEquals('suspiciously_empty', $result->error_code);
$this->assertEmpty($result->items);
```

---

### P3-TC05: Garbage Feed Returns Parse Error

**Priority:** Critical  
**Method:** `test_garbage_feed_returns_parse_failed()`

**Steps:**
```php
// 1. Mock HTTP to return feed-garbage.html
$result = $supplier->fetch();
```

**Expected Result:**
- `$result->ok === false`
- `$result->error_code === 'parse_failed'`

---

### P3-TC06: WP_Error Returns Fetch Failed

**Priority:** Critical  
**Method:** `test_wp_error_returns_fetch_failed()`

**Steps:**
```php
// 1. Mock HTTP to return WP_Error
add_filter('pre_http_request', function() {
    return new WP_Error('http_request_failed', 'Connection refused');
});

$result = $supplier->fetch();
```

**Expected Result:**
- `$result->ok === false`
- `$result->error_code === 'fetch_failed'`
- `$result->error_message` contains useful info

---

### P3-TC07: HTTP 500 Returns Error

**Priority:** Critical  
**Method:** `test_http_500_returns_error()`

**Steps:**
```php
// Mock HTTP 500
add_filter('pre_http_request', function() {
    return ['response' => ['code' => 500], 'body' => 'Server Error'];
});

$result = $supplier->fetch();
```

**Expected Result:**
- `$result->ok === false`
- Error code indicates HTTP failure

---

### P3-TC08: HTTP 200 with Empty Body Returns Error

**Priority:** Critical  
**Method:** `test_http_200_empty_body_returns_error()`

**Steps:**
```php
// Mock HTTP 200 with empty body
add_filter('pre_http_request', function() {
    return ['response' => ['code' => 200], 'body' => ''];
});

$result = $supplier->fetch();
```

**Expected Result:**
- `$result->ok === false`

---

### P3-TC09: Qty Normalisation — Comma-Separated Numbers

**Priority:** High  
**Method:** `test_qty_normalisation_commas()`

**Steps:**
```php
// Feed fixture with qty value "1,234"
// Parse the qty value
```

**Expected Result:**
- Normalised qty = `1234` (integer)

---

### P3-TC10: Qty Normalisation — Negative Numbers

**Priority:** High  
**Method:** `test_qty_normalisation_negative()`

**Steps:**
```php
// Feed fixture with qty value "-5"
```

**Expected Result:**
- Normalised qty = `0` (negative clamped to zero)

---

### P3-TC11: Qty Normalisation — Empty String Skips Row

**Priority:** High  
**Method:** `test_qty_normalisation_empty_skips_row()`

**Steps:**
```php
// Feed fixture with empty qty value
```

**Expected Result:**
- Row is NOT included in `$result->items`
- No error — just silently skipped

---

### P3-TC12: Second Supplier Discoverable via Filter

**Priority:** High  
**Method:** `test_second_supplier_discoverable()`

**Steps:**
```php
// 1. Register a dummy supplier via filter
add_filter('gco_stock_sync_suppliers', function($suppliers) {
    $suppliers['dummy'] = new Dummy_Supplier();
    return $suppliers;
});

// 2. Get all registered suppliers
$suppliers = apply_filters('gco_stock_sync_suppliers', []);
```

**Expected Result:**
- `$suppliers` contains both 'highland_outdoors' and 'dummy'
- Both implement `GCO_Stock_Sync_Supplier_Interface`

---

### P3-TC13: Feed URL Is a Setting, Not Hardcoded

**Priority:** Medium  
**Method:** `test_feed_url_is_configurable()`

**Steps:**
```php
// 1. Change the feed URL setting
// 2. Verify supplier uses the updated URL
```

**Expected Result:**
- Supplier fetches from the configured URL, not a hardcoded constant
- Default URL is the known Highland Outdoors URL

---

### P3-TC14: Below Minimum Rows Triggers Sanity Guard

**Priority:** High  
**Method:** `test_below_minimum_rows_triggers_guard()`

**Steps:**
```php
// 1. Create fixture with 3 product rows (below default minimum of 10)
// 2. Fetch
$result = $supplier->fetch();
```

**Expected Result:**
- `$result->ok === false`
- `$result->error_code === 'suspiciously_empty'`

---

### P3-TC15: Fetch Result Structure Is Correct

**Priority:** Medium  
**Method:** `test_fetch_result_structure()`

**Steps:**
```php
$result = $supplier->fetch();
```

**Expected Result:**
Each item in `$result->items` has exactly these keys:
- `sku` (string, non-empty)
- `qty` (integer, >= 0)
- `name` (string)
- `internal_id` (string)

---

### P3-TC16: Failed Fetch Stores Debug Transient

**Priority:** Medium  
**Method:** `test_failed_fetch_stores_debug_transient()`

**Steps:**
```php
// 1. Force a fetch failure
// 2. Check transient
$debug = get_transient('gco_stock_sync_last_error_body');
```

**Expected Result:**
- Transient contains the raw response body (or portion of it)
- Transient is truncated to ~10KB if the body is larger
