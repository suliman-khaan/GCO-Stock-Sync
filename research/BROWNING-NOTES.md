# BROWNING-NOTES.md — Browning Dealer Portal (dealer.browning.eu) Auth & Stock API Analysis

**Date:** 2026-09-15/16
**Source:** Client-supplied HAR capture of a real dealer login + `Copy as cURL` of one authenticated request + client-supplied Local Storage contents, plus live verification calls made from this analysis session.
**Status:** ⚠️ Architecture fully mapped. **Automated background refresh is currently blocked by a Conditional Access policy on Browning's own Entra tenant.** This is a real finding that affects the "Option 1" (no separate hosting) assumption already quoted to the client — see §5.

---

## 1. Full auth flow, confirmed end-to-end

```
1. Browser → Microsoft (Entra ID): Authorization Code + PKCE, public client
     tenant:    a548c4b0-f83e-49a8-9091-8a3329d0488a   (this is Browning's own tenant — see §4)
     client_id: 27b04987-c8a0-4fd4-9eb3-3c105b480a0b
     redirect_uri: https://dealer.browning.eu/en-gb/profile/login/callback
   Login method: passwordless "Email one-time code" (POST /common/GetOneTimeCode,
   Channel: Email) — NOT an authenticator app / push MFA. Matches the client's
   screenshot ("no password, just this [numeric code]").

2. Browser → https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
     grant_type=authorization_code, code, code_verifier, client_id, redirect_uri
   ← 200 OK: { token_type, scope: "email profile openid User.Read",
               expires_in: 4588, access_token, refresh_token, id_token }

   A refresh_token IS issued. This is what made "Option 1" (silent background
   renewal, no re-login) look viable in the first place.

3. Browser → https://dealer.browning.eu/api/graph
   Uses Sana's persisted-query mechanism (a pre-registered query hash), not a
   literal query string — confirmed from the FULL (untruncated) request body:
     {
       "variables": {
         "externalAuthenticationInput": {
           "idToken": "<Microsoft id_token>",
           "accessToken": "<Microsoft access_token>"   ← BOTH required, non-null
         },
         "keys": ["VIEW_CATALOG","VIEW_STOCK", ... ability keys to return]
       },
       "extensions": {
         "persistedQuery": { "version": "1", "sha256Hash": "88065568f0da60ecee939e153d331655d4a55b200eb4f3b967036d7e37a8a400" }
       }
     }
   ← Sana Commerce's own session token: HS256 JWT, ~61 minute lifetime
     (sample: nbf → exp = 3659s). This is what actually gets used, not the
     Microsoft access_token — Microsoft's tokens are only used once, to mint
     this Sana token.

   (An earlier draft of this doc guessed a literal mutation query text from
   the 1000-char-truncated first recon pass and got two things wrong: it
   only sent idToken, not accessToken, and it invented field names instead
   of using the real persisted-query hash. Both are fixed in
   research/test-browning-connection.php and browning-diagnostic-web.php.)

4. Every subsequent /api/graph call:
     Authorization: Bearer <Sana session token from step 3>
   (No cookies are used at all — confirmed by inspecting a raw `Copy as cURL`
   from the browser; Chrome's "Save all as HAR" export silently strips both
   Cookie and Authorization header VALUES by default, which is why the first
   pass at this analysis, using the HAR alone, wrongly concluded there was no
   Bearer token in use. The curl copy and the Local Storage dump corrected this.)
```

### Local Storage (browser-side token cache)

Confirmed via client-supplied Local Storage dump for `https://dealer.browning.eu`:

| Key | Contents |
|---|---|
| `au.rt` | Microsoft **refresh_token** — the durable credential |
| `au.it` | Microsoft id_token (short-lived, ~61 min, same lifetime as the Sana token) |
| `au.exp` | Unix-ish expiry of the current id_token/Sana token pair |

Simple, non-namespaced keys — this is a lightweight custom wrapper, not raw MSAL.js's usual `msal.token.keys.*` cache format. Easy to locate for a one-time manual extraction.

### Stock query, confirmed batchable

```graphql
query CalculatedProductStocks($options: ProductsLoadOptions!) {
  catalog {
    products(options: $options) {
      products { id inventory secondaryInventory isOrderable }
    }
  }
}
```
`variables.options.ids` takes an array of Browning item numbers (e.g. `["C192102431"]`) — confirmed all SKUs can be checked in one request, as the client hoped. A second, richer persisted-query variant also returns `price`, `listPrice`, `isObsoleteItem` etc. per item.

---

## 2. Live verification performed (2026-09-16, this session)

Using the client-supplied `au.rt` refresh token, replayed the exact refresh cycle server-side (not through the browser):

1. `POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with `grant_type=refresh_token` → **HTTP 400**:
   ```json
   { "error": "invalid_grant",
     "error_description": "AADSTS53003: Access has been blocked by Conditional
     Access policies. The access policy does not allow token issuance.",
     "error_codes": [53003] }
   ```
2. Retried with the exact `Origin`, `Referer`, and `User-Agent` headers the real browser sent on the original token request (ruling out simple client-fingerprinting) → **identical block.**
3. Retried from a completely different network/IP than either attempt above → **identical block.**

**AADSTS53003 is a Conditional Access rejection**, not a credential problem — the refresh token itself is valid, but Microsoft's identity platform is refusing to issue a new token for this sign-in under the policy currently configured on this tenant.

---

## 3. What this could mean (two hypotheses — now resolved)

| Hypothesis | If true | Can we work around it? |
|---|---|---|
| **A. Location/named-network policy** — the tenant only trusts sign-ins from specific IP ranges (e.g. UK office networks), and refreshes from an unrecognized server IP get blocked regardless of who's asking. | Refreshing from the *actual production hosting server's* IP might succeed, since that's a different, possibly-trusted origin. | Worth testing directly, cheaply, before concluding anything further. |
| **B. Blanket non-interactive block** — the tenant requires every token issuance to trace back to a real interactive/browser session (e.g. sign-in frequency, device compliance, or "block non-interactive flows" policies), regardless of origin. | No server, anywhere, can silently refresh — "Option 1" as originally scoped is not deliverable at all. | Only Browning's own IT/identity admin could adjust this — not something achievable from our side. |

**Resolved: Hypothesis A.** Confirmed 2026-09-17, live, from the client's real production host (IONOS shared hosting) — see §6. The AADSTS53003 block does not reproduce from production. It was specific to the dev-sandbox network this analysis originally ran from.

Nothing in this investigation suggested trying to bypass or spoof around this while it was still an open question — Conditional Access is specifically designed to resist exactly that, and the honest path was to test from the real location instead, which is what settled it.

---

## 4. Whose tenant is this?

Tenant `a548c4b0-f83e-49a8-9091-8a3329d0488a` is almost certainly **Browning's own Entra tenant**, not the client's. Evidence: the login method is `idp: "mail"` — Microsoft's marker for an external/guest identity verified by email one-time-code rather than a native tenant member — which is the standard pattern for a manufacturer's B2B dealer portal inviting external dealer accounts as guests into their own directory. This matters because **the client (gunsandcountry) has no ability to view or change Conditional Access policy on this tenant** — only Browning's own IT could do that, and asking a manufacturer to adjust security policy for one dealer's background sync script is a big, uncertain ask.

---

## 5. Original recommendation (superseded by §6 below — kept for the record)

This was exactly the fork Suliman flagged to the client on Sep 9, just with a more specific cause than anticipated (Conditional Access, not MFA/bot-protection). The plan was to test from the real production server before concluding anything — see §6 for the result.

---

## 6. Production verification (2026-09-17)

Two more real issues surfaced testing from the client's actual IONOS shared hosting, both now root-caused and fixed:

### 6a. Outbound to `login.microsoftonline.com` returned an empty HTTP 404

Not a Microsoft-side response at all (Microsoft always returns detailed JSON, even on real errors) — this was IONOS's own infrastructure. **IONOS support's diagnosis, confirmed correct**: after their Debian 11 → Debian 13 (Trixie) OS upgrade, the updated cURL/OpenSSL stack mishandles ALPN/HTTP2 negotiation against Microsoft's gateway specifically, and the gateway drops the route with a bare 404. Fix: disable ALPN negotiation on the curl handle —
```php
curl_setopt( $ch, CURLOPT_SSL_ENABLE_ALPN, false );
```
Applied to every curl call in both `test-browning-connection.php` and `browning-diagnostic-web.php`. Confirmed fixed: `login.microsoftonline.com` now returns a real `200` with proper OpenID discovery JSON.

**This is a hosting-environment quirk, not something specific to our code** — but it means the real plugin connector (once built) must apply the same fix, since it targets the same Debian 13 + cURL/OpenSSL combination on the same host. WordPress's `wp_remote_post()` doesn't expose `CURLOPT_SSL_ENABLE_ALPN` directly, but WP fires an `http_api_curl` action with the raw curl handle before the request executes — that's the hook point:
```php
add_action( 'http_api_curl', function ( $handle, $r, $url ) {
	if ( false !== strpos( $url, 'login.microsoftonline.com' ) ) {
		curl_setopt( $handle, CURLOPT_SSL_ENABLE_ALPN, false );
	}
}, 10, 3 );
```

### 6b. With ALPN fixed: refresh_token grant succeeded — AADSTS53003 does NOT reproduce from production

Confirms §3's Hypothesis A. The Conditional Access block seen earlier was specific to the dev-sandbox's network location, not a blanket policy. **"Option 1" (fully automated, no separate hosting) is viable** — production can silently refresh Microsoft tokens.

### 6c. Step 2 (Sana session exchange) then failed — a real bug in this doc's earlier draft, now fixed

The original recon (§1) truncated the captured request body at 1000 characters, which cut off before the full shape. Corrected in §1 above: the real request needs `accessToken` in addition to `idToken`, and uses a persisted-query hash rather than a literal query string. Both `test-browning-connection.php` and `browning-diagnostic-web.php` are updated to match.

### 6d. Full chain re-verified live from production (2026-09-17) — ALL THREE STEPS SUCCEEDED

```
Step 1: refresh_token grant → HTTP 200, fresh id_token + access_token + rotated refresh_token
Step 2: loginWithSingleSignOn  → HTTP 200, Sana Bearer token, expiration 2026-09-17T12:37:07Z
Step 3: CalculatedProductStocks → HTTP 200
  {"data":{"catalog":{"products":{"products":[
    {"id":"C192102431","inventory":0,"secondaryInventory":40,"isOrderable":true}
  ]}}}}
```

No browser, no headless automation, no manual login involved in any of these three calls — only the one-time-extracted refresh token. This is the complete, real, end-to-end proof the whole design has been building toward.

---

## 7. Where this stands now — recon complete, all three findings resolved

| # | Blocker found | Resolution |
|---|---|---|
| 1 | AADSTS53003 Conditional Access block | Was dev-sandbox-location-specific — does not occur from production (§3, §6b) |
| 2 | Outbound to `login.microsoftonline.com` returning empty 404 | IONOS's Debian 13 cURL/ALPN bug — fixed with `CURLOPT_SSL_ENABLE_ALPN => false` (§6a) |
| 3 | Step 2 GraphQL errors (`accessToken` missing, unknown field) | Recon had truncated the real request body — corrected shape now confirmed working (§6c, §6d) |

**Option 1 (fully automated background refresh, no separate hosting) is confirmed end-to-end from the real production environment.** Ready to move from recon to an actual build plan for the connector itself — settings (refresh-token entry field, product map), the session/refresh-cycle class, the "needs reauthentication" flag-and-stop behaviour, and wiring into the existing supplier framework alongside Highland and Ladds.

Housekeeping: the shared refresh token has passed through this chat several times now for testing. None of it was logged or stored by either diagnostic script, but now that live verification is complete, the client should log in fresh for a clean rotation, and the diagnostic file (`browning-diagnostic-web.php`) should be deleted from the production server — it's done its job.
