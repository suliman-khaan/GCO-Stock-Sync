# Phase 3 — Supplier Abstraction + Highland Outdoors Connector

**Type:** Code  
**Goal:** Fetch and parse the feed into a normalised array. No WooCommerce interaction in this phase.  
**Estimated Effort:** 6–8 hours  
**Prerequisite:** Phase 2 complete (plugin skeleton passes gate)

---

## Tasks

### 3.1 — Supplier Interface (`includes/suppliers/interface-supplier.php`)
```php
interface GCO_Stock_Sync_Supplier_Interface {
    public function get_key(): string;              // 'highland_outdoors'
    public function get_label(): string;            // 'Highland Outdoors'
    public function get_settings_fields(): array;   // for the settings screen
    public function is_configured(): bool;
    public function fetch(): GCO_Stock_Sync_Fetch_Result;
}
```

### 3.2 — Fetch Result Class
`GCO_Stock_Sync_Fetch_Result` with properties:
- `ok` (bool)
- `items` (array of `['sku','qty','name','internal_id']`)
- `error_code` (string|null)
- `error_message` (string|null)
- `raw_row_count` (int)

### 3.3 — Abstract Supplier (`includes/suppliers/abstract-supplier.php`)
- Base implementation of common supplier logic
- Settings storage/retrieval helpers
- Error wrapping

### 3.4 — Supplier Registration Filter
```php
apply_filters('gco_stock_sync_suppliers', $suppliers);
```
New suppliers drop in via this filter — no core code editing required.

### 3.5 — Highland Outdoors Connector (`includes/suppliers/class-highland-outdoors.php`)

1. **Feed URL as a setting** (not hardcoded) — ship known URL as default value
2. **Fetch** via `wp_remote_get()` with 30s timeout and sane user agent
3. **Failure conditions** (return `ok = false`):
   - `WP_Error` from HTTP
   - HTTP status ≠ 200
   - Empty response body
   - Body missing expected header signature
4. **Parse** using `DOMDocument` + `DOMXPath` (HTML table) or string parsing (TSV) per Phase 1 findings
   - Use `libxml_use_internal_errors(true)` for HTML
   - **No regex HTML parsing**
5. **Row filter** — a row is a product only if:
   - SKU cell is non-empty
   - SKU doesn't match a known section-header value
   - Qty parses as integer
6. **Qty normalisation:**
   - Strip commas/whitespace
   - Cast to int
   - Negative values → 0
7. **Debug data:** store raw response body of last failed fetch (truncated to ~10KB) in a transient

### 3.6 — Sanity Guard
- If feed parses with **zero product rows**: return `ok = false`, code `suspiciously_empty`
- If fewer than configurable minimum (default 10): return `ok = false`, code `suspiciously_empty`
- This prevents a feed returning an empty table from marking everything out of stock

---

## Files Created in This Phase

```
includes/suppliers/
├── interface-supplier.php
├── class-fetch-result.php
├── abstract-supplier.php
└── class-highland-outdoors.php

tests/integration/
└── test-supplier-highland.php
```

---

## Quality Gate

```
/wordpress-skills:wp-plugin-review includes/suppliers
/wordpress-skills:wp-sec-review includes/suppliers
```

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Feed format changed since Phase 1 | Parser fails | Re-run Phase 1 reconnaissance |
| DOMDocument not available | Parser won't work | Check `extension_loaded('dom')`, fallback plan |
| Feed has UTF-8 encoding issues | Mangled SKUs | Set `mb_internal_encoding`, test with real data |
