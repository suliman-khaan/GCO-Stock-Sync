# Browning Gun Safes Connector — Development Plan
**Project:** GCO Supplier Stock Sync (v1.1.0 → v1.2.0) · **Supplier #4 (Browning)** · **Recon:** complete · **Plan:** reviewed and confirmed
**Goal:** add Browning (dealer.browning.eu) as a connector inside the existing plugin, without changing any behaviour of Highland Outdoors or Ladds Guns (INFAC), and without hosting anything beyond the existing WordPress site.

Recon is done and the full chain has been proven live against production — see [research/BROWNING-NOTES.md](research/BROWNING-NOTES.md), [research/test-browning-connection.php](research/test-browning-connection.php), [research/browning-diagnostic-web.php](research/browning-diagnostic-web.php). This document is the build plan for turning that proof into the actual connector.

---

## 1. Isolation rules (read this first)

**Files ADDED (new, owned entirely by this job):**
- `includes/suppliers/class-browning.php`
- `includes/suppliers/class-browning-auth-session.php`

**Files TOUCHED (additive only — 2 small edits, smaller than Ladds needed):**

| File | Edit | Risk |
|---|---|---|
| `includes/class-plugin.php` | 2 × `require_once` in `load_dependencies()`, 2 lines in `register_builtin_suppliers()` | None — additive, same pattern as Ladds |
| `admin/class-settings-page.php` | Optional, duck-typed status-notice hook in `render_supplier_tab()` (see §5) | Low — backward compatible, no-op for Highland/Ladds |

**Nothing else needs touching this time** — the per-supplier settings tabs, the generic sanitizer fallback (checkbox/number/textarea/password/url by declared type), and the dynamic nav all already exist from the Ladds work and apply to any new supplier automatically. That generalisation is paying for itself here.

**Do NOT touch:** `class-sync-runner.php`, `class-product-matcher.php`, `class-logger.php`, `class-installer.php`, `abstract-supplier.php`, `interface-supplier.php`, `class-highland-outdoors.php`, `class-ladds-infac.php`, `class-odoo-session.php`, the meta box, the log page, the DB tables.

The 4 non-negotiable safety rules in `class-sync-runner.php` stay untouched — status only, never quantity; failed fetch never touches products; absent products left alone; one item can't abort a run.

---

## 2. What makes Browning different from Highland and Ladds

| | Highland | Ladds (INFAC) | Browning |
|---|---|---|---|
| Auth | None (URL contains its own hash) | None (anonymous, stateless) | Microsoft OAuth2 refresh token → Sana session token |
| Request shape | One request → whole catalogue | One request **per product** | One batched request → **all mapped products** |
| Manual step ever needed | Only if Highland rotates the hash | Never | Once, and again whenever Microsoft eventually revokes the refresh token |

Browning is the first connector where "failed to fetch" can mean two genuinely different things: *the supplier is unreachable right now* (transient, no action needed) vs *the stored credential no longer works and a human has to paste in a new one* (needs attention). §4 below treats these differently.

---

## 3. Confirmed technical flow (from recon)

```
1. One-time, by a human, in a real browser (never automated — see §3.1):
     Log into https://dealer.browning.eu/ → DevTools → Application →
     Local Storage → https://dealer.browning.eu → key "au.rt"
   → the Microsoft refresh_token. This is the only manual step, ever,
     unless Microsoft later revokes it.

2. Entirely server-side, every sync run:
     POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
       grant_type=refresh_token, client_id, refresh_token, scope, redirect_uri
     ← access_token, id_token, refresh_token (SAVE the new one — Microsoft
       rotates it on every use; the old one becomes invalid)

3.   POST https://dealer.browning.eu/api/graph
       extensions.persistedQuery.sha256Hash = 88065568f0da60ecee939e153d331655d4a55b200eb4f3b967036d7e37a8a400
       variables.externalAuthenticationInput = { idToken, accessToken }  (both required)
       variables.keys = [ "VIEW_CATALOG", "VIEW_STOCK" ]
     ← Sana session token (~61 min lifetime) — cache this, don't refresh
       Microsoft on every request

4.   POST https://dealer.browning.eu/api/graph
       Authorization: Bearer <Sana session token>
       query CalculatedProductStocks($options: ProductsLoadOptions!) {
         catalog { products(options: $options) {
           products { id inventory secondaryInventory isOrderable }
         } }
       }
       variables.options.ids = [ all mapped Browning item numbers in one call ]
     ← { id, inventory, secondaryInventory, isOrderable } per item
```

Constants (fixed, not admin-configurable — they're the SSO app registration's own IDs, not something that rotates like Highland's URL hash):
```php
const TENANT              = 'a548c4b0-f83e-49a8-9091-8a3329d0488a';
const CLIENT_ID            = '27b04987-c8a0-4fd4-9eb3-3c105b480a0b';
const REDIRECT_URI          = 'https://dealer.browning.eu/en-gb/profile/login/callback';
const SCOPE                = 'email profile openid User.Read offline_access';
const SSO_PERSISTED_QUERY_HASH = '88065568f0da60ecee939e153d331655d4a55b200eb4f3b967036d7e37a8a400';
```

### 3.1 Why the initial login stays manual (important — do not try to automate this)

We don't control Browning's Azure app registration, so we can't add our own redirect URI and can't have Microsoft hand a fresh refresh token to our server directly. This isn't a corner we're cutting for cost — it's a hard boundary of the architecture, confirmed during recon. Nothing about this build should attempt a headless-browser or scripted login; that was explicitly the more expensive, more fragile "Option 2" the client already declined.

### 3.2 The IONOS ALPN fix carries over into real plugin code

Confirmed live (§6a of BROWNING-NOTES.md): this specific host's cURL/OpenSSL stack (post Debian 13 upgrade) mishandles ALPN negotiation against Microsoft's gateway specifically, returning a bare 404. The connector must apply the same fix, scoped only to Microsoft's domain — never touching any other request from this site:
```php
add_action( 'http_api_curl', function ( $handle, $r, $url ) {
	if ( false !== strpos( $url, 'login.microsoftonline.com' ) ) {
		curl_setopt( $handle, CURLOPT_SSL_ENABLE_ALPN, false );
	}
}, 10, 3 );
```
Registered once, in `class-browning-auth-session.php`'s constructor — self-contained, no shared-file edit needed for this part. If this host's environment ever changes (IONOS fixes it properly on their end), this hook is a no-op cost, not a liability — safe to leave in permanently.

---

## 4. Class design

### 4.1 `GCO_Stock_Sync_Browning_Auth_Session`
Single responsibility: get a currently-valid Sana Bearer token, refreshing through Microsoft only when actually needed.

| Method | Behaviour |
|---|---|
| `get_bearer_token()` | Returns a valid Sana token string, or a `WP_Error`/null on failure. Checks a transient cache (`gco_ss_browning_sana_token`) first; if missing/expiring within 2 minutes, runs the full refresh cycle. |
| `refresh_cycle()` (private) | Step 2 → Step 3 from §3. On success: caches the Sana token (transient, TTL = actual `expiration` minus a safety buffer), persists the **rotated** Microsoft refresh_token via `update_option()`, clears the reauth flag. On a Microsoft `invalid_grant`/OAuth-error response specifically: sets the reauth-needed flag (see §4.3) and returns failure. On any other failure (network, Sana-side error): returns failure **without** touching the reauth flag — that's a transient issue, not a "credential is dead" signal. |

### 4.2 `GCO_Stock_Sync_Browning` (extends `GCO_Stock_Sync_Abstract_Supplier`)

| Method | Behaviour |
|---|---|
| `get_key()` | `browning` |
| `get_label()` | `Browning Gun Safes` |
| `get_settings_fields()` | `refresh_token` (password), `product_map` (textarea), `min_success_ratio` (number), `request_timeout` (number) — `refresh_token` stored plain in `wp_options` via the existing `password` → trim + raw store sanitizer, same as every other setting in this plugin. Confirmed in plan review: no new encryption layer, for consistency with the rest of the codebase. |
| `is_configured()` | `refresh_token` setting non-empty **and** map has ≥1 valid row. (Deliberately does *not* check whether the token still works — that's what a sync attempt and the reauth flag are for, not a settings-page validity check that would need a live network call on every admin page load.) |
| `fetch()` | Parse map → ask the auth session for a Bearer token → one batched `CalculatedProductStocks` call for every mapped item number → normalise → ratio gate → `GCO_Stock_Sync_Fetch_Result` |

`fetch()` returns the same normalised item shape every other connector uses, so the matcher/logger/runner need no changes:
```php
array( 'sku' => 'GCO-SKU', 'qty' => 4, 'name' => 'GCO-SKU', 'internal_id' => 'C192102431' )
```

### 4.3 Reauthentication flag

A small, self-contained option (`gco_ss_browning_needs_reauth`, plus a stored message/timestamp), set only by `refresh_cycle()` on a genuine Microsoft credential rejection, cleared automatically the next time a refresh succeeds (i.e. the moment someone pastes in a working token and a sync runs). No new DB table, no schema change.

`fetch()` surfaces this as `GCO_Stock_Sync_Fetch_Result::failure( 'needs_reauthentication', ... )` — a **new error code**, but `Fetch_Result::failure()` already accepts an arbitrary string, so this needs no change to that class. Runner safety rule #2 already guarantees a failed fetch never touches a product; this is just a more specific reason shown in the log than a generic "fetch failed".

### 4.4 Product mapping

Same textarea pattern as Ladds, one difference: Browning item numbers are alphanumeric (`C192102431`), not purely numeric, so the parser validates "non-empty after trim", not `absint() > 0`.

```
# woo_sku , browning_item_number
GCO-BR-SD14 , C192102431
```
Invalid lines (wrong field count, blank SKU or item number) dropped and counted, same as Ladds — never a hard failure over one bad line.

### 4.5 Qty normalisation

Use `inventory` as the single source of truth — this is the exact field the client cross-checked against the portal's own "4 in stock"/"6 in stock" display. `secondaryInventory` showed up in testing as a genuinely different number (a separate stock pool, not a fallback-when-missing like Ladds' `delivery_stock_data`), so **it is not used** unless the client explicitly asks for it later — using it without being asked would risk showing stock that isn't actually available through this channel. `isOrderable` is present but, per the client's own Ladds-era warning about similar flags being unreliable, is **not** used to override the qty-based status — same `(int) floor()` / clamp-negative-to-zero / zero-only-on-explicit-numeric-zero rules as the other two connectors.

---

## 5. The one (optional, backward-compatible) shared edit

`render_supplier_tab()` currently has no way for a connector to say "something needs your attention" beyond the existing configured/unconfigured dot in the nav (which only reflects *settings filled in*, not *credential still valid*). Rather than hardcoding Browning-specific banner logic into the shared settings page, add one small, duck-typed hook any current or future supplier can optionally use:

```php
if ( method_exists( $supplier, 'get_status_notices' ) ) {
	foreach ( $supplier->get_status_notices() as $notice ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
	}
}
```
Highland and Ladds don't implement `get_status_notices()`, so `method_exists()` is `false` and this is a pure no-op for them — zero behaviour change, zero risk. `GCO_Stock_Sync_Browning::get_status_notices()` returns a one-line "Browning needs reauthentication — paste a fresh token below" message when the §4.3 flag is set, empty array otherwise.

---

## 6. Build order

1. ~~Recon~~ — **done** (research/BROWNING-NOTES.md).
2. `class-browning-auth-session.php` — refresh cycle, Sana exchange, ALPN hook, transient caching, reauth flag get/set. Test with a throwaway CLI script against the real endpoints, same pattern as Ladds' Phase 2, before wiring anything.
3. `class-browning.php` — map parser, `fetch()`, ratio gate, qty normalisation.
4. The optional `get_status_notices()` hook in `class-settings-page.php`.
5. Register in `class-plugin.php`; regression-test Highland *and* Ladds afterward (both, since this is the third supplier sharing that file).
6. Version bump 1.1.0 → 1.2.0.
7. Map the real Browning gun-safe item numbers once the client sends the list (mirrors Ladds' "waiting on client URLs" step).

---

## 7. Test checklist before delivery

- [ ] Highland connector: sync still identical to pre-change (row/update counts, log rows).
- [ ] Ladds connector: sync still identical to pre-change.
- [ ] Browning: Test Connection returns a count without writing anything to the DB or any product.
- [ ] Browning: a full live run correctly flips a known in-stock and a known out-of-stock item.
- [ ] Simulate an expired/revoked refresh token → confirm `needs_reauthentication`, zero products changed, reauth flag set, nav shows the warning.
- [ ] Paste a fresh working token → confirm the flag clears on the next successful run.
- [ ] Kill network to `dealer.browning.eu` mid-run → confirm zero products changed, run logs as `failed` (not `needs_reauthentication` — that's specifically for credential rejection).
- [ ] Corrupt several map rows → confirm the ratio gate trips and nothing changes.
- [ ] Confirm the ALPN hook only fires for `login.microsoftonline.com` requests — nothing else on the site is affected.
- [ ] All three suppliers configured → single cron tick runs all three, lock behaves, no cross-contamination in logs.
- [ ] `wp gco-stock-sync run --supplier=browning --dry-run` works.

---

## 8. Deliverables

- Updated plugin, v1.2.0
- The gun-safe item-number mapping pre-filled in settings once the client sends the list
- Short note for the client: what "needs reauthentication" means in practice, and exactly how to fix it (log in once, paste the new value from Local Storage, save)
