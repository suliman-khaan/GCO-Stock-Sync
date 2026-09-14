# LADDS-NOTES.md — Ladds Guns (INFAC) Odoo Endpoint Analysis

**Date:** 2026-09-15
**Analyst:** Automated reconnaissance (browser devtools replay + independent `curl` verification)
**Endpoint:** `POST https://www.laddsguns.com/website_sale/get_combination_info`
**Status:** ✅ Endpoint is LIVE and responding. ✅ Anonymous access confirmed sufficient.

---

## 1. Decision gate: is a session needed at all?

**No.** Tested three ways, all succeeded identically:

1. Browser page load (with normal cookies) → `200`, correct data.
2. Browser `fetch()` with `credentials: 'omit'` (zero cookies sent, same-origin) → `200`, identical `result`.
3. Plain `curl` from an unrelated host, **no cookies, no `Referer`, no CSRF token, no custom User-Agent** → `200`, identical `result`.

The server issues a fresh `session_id` cookie on every anonymous request (`Set-Cookie: session_id=...; HttpOnly; Secure; SameSite=Lax; Max-Age=604800`) but **does not require it to be sent back**. Each request is stateless from the client's point of view.

**Consequence for §3.3 of the build plan:** `class-odoo-session.php` does not need to *establish* a session to make requests work — the endpoint works with no cookie jar at all. It's still worth keeping a thin session helper that:
- Sends whatever cookie we last received back on subsequent requests (politeness / consistent with how a real browser behaves, lower chance of being flagged as a bot), but
- Does **not** treat a missing/rejected cookie as a failure condition, and
- Does **not** need a login/establish step, credentials, or a "verify anonymous access" gate before building the rest — that step is already answered.

This simplifies the connector: no auth flow, no credentials needed from Ladds.

**CSRF token:** Not required — confirmed by the `curl` test (`type="json"` route, no CSRF header sent, still worked).
**Referer:** Not required — confirmed by the `curl` test (no `Referer` header sent at all, still worked).

---

## 2. Confirmed request/response shape

### Request (exactly matches the plan's example)
```json
POST https://www.laddsguns.com/website_sale/get_combination_info
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "method": "call",
  "params": {
    "product_template_id": 11950,
    "product_id": 14209,
    "combination": [],
    "add_qty": 1,
    "pricelist_id": null,
    "parent_combination": [],
    "context": {}
  }
}
```

### Success response (INFAC SD14, product_template_id=11950, product_id=14209)
```json
{
  "jsonrpc": "2.0",
  "id": null,
  "result": {
    "product_id": 14209,
    "product_template_id": 11950,
    "display_name": "INFAC SD14",
    "free_qty": 46,
    "delivery_stock_data": { "in_stock": true, "show_quantity": false, "quantity": 46.0 },
    "in_store_stock_data": { "in_stock": true, "show_quantity": false, "quantity": 46.0 },
    "allow_out_of_stock_order": true,
    ... (price, uom, wishlist flags — unused by the connector)
  }
}
```
`free_qty` (int, 46) and `delivery_stock_data.quantity` (float, 46.0) agree — cross-check passes as expected.

Second product tested (INFAC Spare Internal Shelf - SD14, template=11966, product=14225): `free_qty: 2`, `delivery_stock_data.quantity: 2.0` — also agree, low-stock case confirmed working.

**Ignore `allow_out_of_stock_order`** — both products return `true` for this flag regardless of actual stock level, confirming the client's warning that it's unreliable as a stock signal.

### Error response shape (invalid/deleted product IDs)
```json
{
  "jsonrpc": "2.0",
  "id": null,
  "error": {
    "code": 0,
    "message": "Odoo Server Error",
    "data": {
      "name": "odoo.exceptions.MissingError",
      "message": "Record does not exist or has been deleted.\n(Record: product.template(999999999,), User: 3)",
      ...
    }
  }
}
```
**Parsing rule for `fetch()`:** presence of a top-level `"error"` key (regardless of `error.data.name`) = that product's request failed → omit from `items` per §3.5. HTTP status was still `200` for this case, so **do not rely on HTTP status alone** — always check for the `error` key in the decoded JSON body first.

A malformed/incomplete JSON-RPC payload produces the same `{"error": {...}}` shape (tested: dropping `params` entirely → `TypeError: missing required positional arguments`). Same handling applies.

---

## 3. Build plan §3.3 — answers to the open questions

| Question | Answer |
|---|---|
| Does anonymous access work? | **Yes**, fully — no credentials needed from Ladds. Scope unchanged. |
| Does the `type="json"` route need a CSRF token? | **No** — confirmed by header-free `curl` request. |
| Is a `Referer` header required? | **No** — confirmed by header-free `curl` request. |
| Does a session/cookie need to be established first? | **No** — every request is independently anonymous-capable. Session helper becomes a nice-to-have (cookie passthrough), not a prerequisite. |

No scope change. No client follow-up needed on credentials.

---

## 4. Other observations

- Server: `Odoo.sh` (managed Odoo Online), TLS only, HSTS enabled.
- Response `Content-Type: application/json; charset=utf-8`, gzip-compressed.
- `free_qty` is already an integer in both samples tested; `delivery_stock_data.quantity` is a float (`46.0`, `2.0`) — confirms §3.6's `(int) floor()` normalisation approach is correct and necessary for the fallback field.
- Product page HTML exposes `product_id` / `product_template_id` as hidden form inputs (`input[name="product_id"]`, `input[name="product_template_id"]`) — confirms §6 of the build plan: IDs are visible in page source without needing devtools, useful for mapping the remaining 11 products once URLs arrive.
- Product URL pattern observed: `https://www.laddsguns.com/shop/lg<template_id>-<slug>-<template_id>` (e.g. `/shop/lg11950-infac-sd14-11950`).

---

## 5. Recon verdict

**Anonymous access is sufficient. No scope change. Proceed to Phase 2** (build `class-odoo-session.php` + `class-ladds-infac.php`) per the build plan, with the session helper simplified per §1 above (cookie passthrough only, no establish/login step required).
