# INFAC / Ladds Guns Connector — Development Plan
**Project:** GCO Supplier Stock Sync (v1.0.0) · **Supplier #3** · **Assigned:** Sid
**Goal:** add Ladds Guns (INFAC brand) as a connector inside the existing plugin, without changing any behaviour of the Highland Outdoors connector or the core sync engine.

---

## 1. Isolation rules (read this first)

The plugin already has a supplier abstraction, so a new supplier is a **new class + registration**, nothing else.

**Files ADDED (new, owned entirely by this job):**
- `includes/suppliers/class-ladds-infac.php`
- `includes/suppliers/class-odoo-session.php` (Odoo storefront session helper)

**Files TOUCHED (additive only — 3 small edits):**

| File | Edit | Risk |
|---|---|---|
| `includes/class-plugin.php` | 2 × `require_once` in `load_dependencies()`, 2 lines in `register_builtin_suppliers()` | None — additive |
| `admin/class-settings-page.php` | `sanitize_supplier_settings()` — add a generic type-driven fallback for unknown keys (see §4) | Low — Highland's keys stay explicitly handled first |
| `gco-stock-sync.php` | version bump `1.0.0 → 1.1.0` | None |

**Do NOT touch:** `class-sync-runner.php`, `class-product-matcher.php`, `class-logger.php`, `class-installer.php` (no schema change), `abstract-supplier.php`, `interface-supplier.php`, `class-highland-outdoors.php`, the meta box, the log page, or the DB tables.

The 4 non-negotiable safety rules in `class-sync-runner.php` stay untouched — status only, never quantity; failed fetch never touches products; absent products left alone; one item can't abort a run.

---

## 2. What makes INFAC different from Highland

Highland = **one request → whole catalogue**.
Ladds = **one request per product**, and the request needs Odoo internal IDs, not SKUs.

```
POST https://www.laddsguns.com/website_sale/get_combination_info
{"jsonrpc":"2.0","method":"call","params":{
   "product_template_id": 11950,
   "product_id": 14209,
   "combination": [],
   "add_qty": 1,
   "pricelist_id": null,
   "parent_combination": [],
   "context": {}
}}
```
Response → `result.free_qty` (primary), `result.delivery_stock_data.quantity` (cross-check).
**Ignore `allow_out_of_stock_order`** and ignore the presence of an Add to Cart button — confirmed unreliable by the client.

13 products in scope. Client is sending the product URLs.

---

## 3. Connector design

### 3.1 Class shape
`GCO_Stock_Sync_Ladds_Infac extends GCO_Stock_Sync_Abstract_Supplier`

| Method | Behaviour |
|---|---|
| `get_key()` | `ladds_infac` |
| `get_label()` | `Ladds Guns (INFAC)` |
| `get_settings_fields()` | endpoint base URL, product map, min success ratio, request timeout |
| `is_configured()` | base URL is https **and** map has ≥1 valid row |
| `fetch()` | loop the map, return one `GCO_Stock_Sync_Fetch_Result` |

`fetch()` returns the exact same normalised item shape the runner already expects, so the product matcher and logger need no changes:
```php
array( 'sku' => 'GCO-SKU', 'qty' => 46, 'name' => 'GCO-SKU', 'internal_id' => '11950:14209' )
```

### 3.2 Product mapping
Stored as a **textarea inside the connector's own settings** — no new DB table, no new product meta, no meta-box changes.

```
# woo_sku , product_template_id , product_id
INFAC-SD14 , 11950 , 14209
INFAC-SD20 , 11951 , 14212
```
Parser: skip blanks and `#` comments, trim, require exactly 3 fields, both IDs must be `absint() > 0`, SKU non-empty. Invalid lines are dropped and counted, and the count is surfaced in the settings screen and in the run message — never silently ignored.

13 rows makes a textarea the right call. If it ever grows past ~50, revisit with a CSV import, not before.

### 3.3 Odoo session handling
`GCO_Stock_Sync_Odoo_Session` — small helper, single responsibility:

1. Check transient `gco_ss_ladds_session` (TTL 6 h).
2. If absent: `wp_remote_get()` the shop root, read the `session_id` cookie from the response headers, store it.
3. Attach the cookie to every JSON-RPC POST via the `cookies` arg of `wp_remote_post()`.
4. On HTTP 4xx, an Odoo `error` object, or a session-expired response: delete the transient, re-establish **once**, retry that one product. No second retry — avoid hammering them.

The client thinks anonymous access may be enough. **First job of the build is to verify that**, because it decides whether we need credentials from Ladds at all. If an anonymous session turns out to be insufficient, stop and report before writing the rest — that's a scope change, not a silent workaround.

Also to confirm at that stage: whether `type="json"` route needs a CSRF token (normally it does not) and whether a `Referer` header is required.

### 3.4 Per-request behaviour
- Timeout **15 s** per request, sequential, **300 ms** pause between requests (13 × ~1 s ≈ 15–25 s total — comfortably inside the 15-min sync lock).
- Total wall-clock cap of **120 s**; if exceeded, stop and return what was collected (subject to the ratio gate below).
- User-agent: same string Highland uses.
- SSRF guard: reuse Highland's https-only URL validation; host must resolve to `laddsguns.com`.

### 3.5 Failure semantics — the important bit
| Situation | Result |
|---|---|
| One product's request fails / unparseable | That product is **omitted from `items`**. Runner rule #3 then leaves that Woo product completely alone. Logged with a note. |
| Fewer than **60 %** of mapped products returned a usable qty | Whole fetch returns `failure('suspiciously_empty', …)` → runner rule #2 → **nothing on the site changes**. Configurable. |
| Session cannot be established at all | `failure('fetch_failed', …)` → nothing changes. |
| `free_qty` missing but `delivery_stock_data.quantity` present | Use the fallback, log the note. |
| Both missing | Treat as a per-product failure (omit) — **never** as qty 0. |

Zero is only ever written as out-of-stock when Ladds explicitly returns a numeric zero.

### 3.6 Qty normalisation
`(int) floor()` of the float, negatives clamped to 0, threshold stays **exactly 0** (no buffer) to match Highland. Status mapping is the runner's job, not ours.

---

## 4. The one shared-code edit, explained

`sanitize_supplier_settings()` currently whitelists only `enabled`, `feed_url`, `min_rows`. Any new key a connector declares is **silently dropped on save**. So the map textarea would never persist.

Fix: after the three existing explicit branches, add a fallback loop over `$supplier->get_settings_fields()` that sanitizes any remaining declared key by its declared `type` (`textarea` → `sanitize_textarea_field`, `number` → `absint`, `text` → `sanitize_text_field`, `password` → trim + raw store). Undeclared keys stay dropped.

Highland's three keys are handled before the loop, so its saved values and validation messages are byte-identical. **This same edit is what Browning will need for its credential fields**, so it gets done once here and Suliman inherits it.

Settings page also needs `case 'textarea':` in the field renderer (currently falls through to a single-line text input). Additive, no existing field uses it.

---

## 5. Known shared-state niggle (log it, don't fix it now)

`store_debug_body()` writes to one global transient `gco_stock_sync_last_error_body`, so with 2+ suppliers the last failure wins and the admin debug view can show the wrong supplier's body. Harmless to stock data. Suggest suffixing the key per supplier when Browning lands — flag it to Zack, don't do it inside this job.

---

## 6. Build order

1. **Recon (Sid, before any code):** replay the exact request from browser devtools on the INFAC SD14. Confirm anonymous session works, capture the exact payload and the full response JSON. Save as `research/LADDS-NOTES.md`.
2. `class-odoo-session.php` + a throwaway CLI script to prove 3 products end-to-end.
3. `class-ladds-infac.php` — map parser first, then `fetch()`.
4. Settings sanitizer fallback + `textarea` renderer.
5. Register in `class-plugin.php`, version bump.
6. Map all 13 products once the client's URLs arrive (template/product IDs are visible in the page source or the devtools request).

---

## 7. Test checklist before delivery

- [ ] Highland connector: run a sync, confirm identical result to pre-change (row counts, updated counts, log rows).
- [ ] Highland settings: save the form, confirm `feed_url` + `min_rows` unchanged and validation errors still fire on a non-https URL.
- [ ] Ladds: Test Connection button returns a count without writing anything to the DB or any product.
- [ ] Ladds live run: verify one known in-stock and one known out-of-stock product flip correctly.
- [ ] Kill the network to laddsguns.com mid-run → confirm **zero** products changed and the run logs as `failed`.
- [ ] Corrupt 6 of 13 map rows → confirm the ratio gate trips and nothing changes.
- [ ] Disable sync on one product via the meta box → confirm it is skipped.
- [ ] `wp gco-stock-sync run --supplier=ladds_infac --dry-run` works.
- [ ] Both suppliers configured → single cron tick runs both, lock behaves, no cross-contamination in the logs.
- [ ] Product with `manage_stock` on → quantity untouched, warning note logged.

---

## 8. Deliverables

- Updated plugin zip, v1.1.0
- `research/LADDS-NOTES.md` (endpoint findings)
- The 13-row mapping pre-filled in settings
- Short handover note for James: where the map lives, how to add a 14th product

**Quoted / accepted:** £105.24 (INFAC) · 7-day delivery · Browning (£164.03, Suliman) runs in parallel and depends on the §4 sanitizer edit landing first.
