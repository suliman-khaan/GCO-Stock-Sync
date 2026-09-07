# FEED-NOTES.md — Highland Outdoors Feed Analysis

**Date:** 2026-09-07  
**Analyst:** Automated reconnaissance  
**Endpoint:** `https://687183.app.netsuite.com/app/reporting/webquery.nl?compid=687183&entity=-5&email=johnb@highlandoutdoors.co.uk&role=1050&cr=1965&hash=AAEJ7tMQgQelTtsA_bfgPhqV1JBNZ5sMB754qObS-sQzpt41Nuw`  
**Status:** ✅ Endpoint is LIVE and responding

---

## 1. HTTP Response Details

| Field | Value |
|-------|-------|
| HTTP Status | **200 OK** |
| Content-Type | `text/html;charset=utf-8` |
| Protocol | HTTP/1.1 over TLS |
| Response Size | **28,346 bytes** (28 KB) |
| SSL | TLS with renegotiation (schannel) |
| HSTS | `max-age=31536000` |
| Other Headers | `X-Content-Type-Options: nosniff`, `Vary: user-agent` |

### Auth Requirements

**None.** The URL is fully self-contained. All authentication is embedded in the URL parameters (`compid`, `entity`, `email`, `role`, `cr`, `hash`). No cookies, session tokens, or HTTP headers are needed. A plain `GET` request returns the full feed.

> **⚠️ The `hash` parameter may rotate.** If the feed stops working, the client must contact Highland Outdoors (johnb@highlandoutdoors.co.uk) for a fresh URL.

---

## 2. Response Format

The response is an **HTML document** containing a single `<table>`:

```html
<html>
<head><meta http-equiv="content-type" content="text/html; charset=utf-8"></head>
<body>
<table>
  <tr><td>Qty Available</td><td>Trade Price</td><td>Name</td>...</tr>
  <tr><td>=8</td><td>=311</td><td>BSEC10</td>...</tr>
  ...
</table>
</body>
</html>
```

**Key structural facts:**
- Single `<table>` element, no `<thead>` / `<tbody>` distinction
- First `<tr>` is the header row
- All subsequent `<tr>` elements are data rows (products + section headers mixed together)
- No CSS classes, IDs, or attributes on any elements — just bare `<td>` tags
- Multiline text appears in Description cells (contains `\r\n` line breaks)

---

## 3. Column Layout — DIFFERS FROM ORIGINAL SPEC

> **⚠️ IMPORTANT: The feed has 6 columns, NOT 8 as the original .xlsx suggested.** The duplicate "Trade Price" and "Qty Available" columns (G and H from the spec) are NOT present.

| Index | Header Text | Data Format | Purpose |
|-------|-------------|-------------|---------|
| 0 | `Qty Available` | `=N` (integer with `=` prefix) | Stock quantity |
| 1 | `Trade Price` | `=N` or `=N.NN` (with `=` prefix) or empty | Trade price (unused by plugin) |
| 2 | `Name` | Plain text | **This is the SKU** — matches WooCommerce SKU |
| 3 | `Brand Name` | Plain text | Brand (Boston Security / Buffalo River) |
| 4 | `Description` | Plain text, may be multiline | Long description (unused by plugin) |
| 5 | `Internal ID` | Integer as text | NetSuite internal ID (store for reference) |

### Critical Data Format Quirk: `=` Prefix

All numeric values (Qty, Price) are prefixed with `=`. This is a NetSuite web query artefact (Excel formula notation).

**Parsing rule:** Strip the leading `=` before casting to integer/float.

Examples:
- `=8` → `8`
- `=311` → `311`
- `=0` → `0`
- `=10.67` → `10.67` (one product has a decimal price)
- Empty string → no value (empty `<td></td>`)

---

## 4. Row Counts

| Category | Count |
|----------|-------|
| Total `<tr>` rows | **135** |
| Header row | **1** |
| Section-header rows | **23** |
| Product rows (real SKUs) | **111** |

---

## 5. Section-Header Row Identification Rule

Section-header rows are category/group dividers. They are **NOT products** and must be filtered out.

### The Rule

A row is a **section header** if it satisfies **BOTH**:
1. **Trade Price cell is empty** (empty `<td></td>`, not `=0`)
2. **Qty Available is `=0`**

This rule correctly identifies all 23 section headers with zero false positives.

### Why not use "Name equals Brand" as the rule?

The first "Boston Security" row has `Name = Brand Name = "Boston Security"`, which seems like a useful signal. However, this pattern only applies to the two brand-level headers. Sub-category headers like "Cabinets", "Accessories", "Bronze Series" don't match their brand name. The **empty price + zero qty** rule is simpler and catches all cases.

### Why not just check for empty price?

Because **many real products also have empty prices** (accessories/parts with no trade price listed). The `=0` qty check is needed to disambiguate. Products with empty prices but non-zero qty are real products (e.g. `BRASL` qty=101, `BRGCBL3` qty=95).

### Edge Cases Handled

| Row | Price | Qty | Classification | Why |
|-----|-------|-----|----------------|-----|
| `Boston Security` | empty | `=0` | **Section header** ✓ | Despite having a description, empty price + zero qty |
| `Cabinets` | empty | `=0` | **Section header** ✓ | No price, no qty |
| `BRASL` | empty | `=101` | **Product** ✓ | Empty price BUT non-zero qty |
| `BRBDKP2` | empty | `=0` | **Ambiguous** ⚠️ | Looks like a section header but is likely an OOS product |
| `Gun Bags & Accessories` | empty | `=0` | **Section header** ✓ | Category name, despite having description |
| `Gunbags` | empty | `=0` | **Section header** ✓ | Sub-category, despite having description |

> **⚠️ Ambiguity:** Some real product SKUs may have empty price AND zero qty (e.g. `BRBDKP2`, `BRBDMDL`, `BRBEL`, `BRFPS`, `BRGSL`, `BRLCDKP`, `BRSOLS`). Under the current rule, these would be classified as section headers and excluded. **However, this is SAFE** — these are out-of-stock products, so even if included, the sync result would be the same (qty=0 → outofstock). Excluding them means the sync won't process them, which is the safer choice.

### Complete List of Section Headers Found

| Name | Brand | Notes |
|------|-------|-------|
| Boston Security | Boston Security | Brand-level header |
| Cabinets | Boston Security | Sub-category |
| Buffalo River | Buffalo River | Brand-level header |
| Accessories | Buffalo River | Sub-category |
| Cabinets | Buffalo River | Sub-category (same name, different brand) |
| Ammunition Cabinets | Buffalo River | Sub-category |
| Black Diamond Series | Buffalo River | Product line |
| Bronze Series | Buffalo River | Product line |
| Essential Series | Buffalo River | Product line |
| Gold Series | Buffalo River | Product line |
| Platinum Series | Buffalo River | Product line |
| Signature Series | Buffalo River | Product line |
| Silver Series | Buffalo River | Product line |
| Z - Discontinued - DO NOT RE-ORDER | Buffalo River | Archive category |
| Z - Safe Accessories | Buffalo River | Sub-category |
| Franzen Solingen | Buffalo River | Sub-brand |
| CarryPRO Competitor | Buffalo River | Product line |
| CarryPRO II Deluxe Series Bags | Buffalo River | Product line |
| CarryPRO II Standard Series Bags | Buffalo River | Product line |
| Dominator | Buffalo River | Product line |
| Economy II Series Bags | Buffalo River | Product line |
| Field Shotgun Slips | Buffalo River | Product line |
| Knives | Buffalo River | Sub-category |
| Z - Discontinued Items | Buffalo River | Archive category |

---

## 6. Product Row Identification Rule (for parser implementation)

A row is a **valid product** if it passes ALL of:

1. It is NOT the header row (first `<tr>`)
2. It has at least 6 `<td>` cells
3. The `Name` cell (index 2) is non-empty after trimming
4. It is NOT a section header (i.e., it does NOT satisfy: empty price AND qty `=0`)

**Simplified:** A data row is a product if `Trade Price is non-empty OR Qty != '=0'`.

### Qty Normalisation Rules

1. Strip leading `=` character
2. Strip commas (not observed but defensive)
3. Strip whitespace
4. Cast to integer
5. Negative values → 0
6. Non-parseable → skip row (log warning)

---

## 7. Brands in Feed

Only **2 brands** currently in the feed:
- **Boston Security** — gun safes/cabinets (7 product SKUs)
- **Buffalo River** — cabinets, accessories, gun bags, knives (104 product SKUs)

---

## 8. Observations & Notes

1. **Decimal prices exist:** `BRKGOO` has price `=10.67`. The plugin doesn't use prices, but the parser should handle decimals gracefully if future use is needed.

2. **Discontinued products are in the feed:** Several products have descriptions starting with "Discontinued - Replaced with..." (e.g. `Q4510D`, `Q5514D`). These have qty=0 and are valid product rows — the sync will correctly mark them outofstock.

3. **Response is stable:** The URL works as a simple GET with no cookies or session state.

4. **No pagination:** All data is in a single response (28KB for ~135 rows). No need for multi-page fetching.

5. **UTF-8 encoding confirmed:** The meta tag declares `charset=utf-8` and the Content-Type header confirms it. Some descriptions contain Unicode characters (zero-width spaces `​` / `\u200B`).

6. **Description field has embedded HTML entities:** `&amp;` appears in at least one row (`Gun Bags & Accessories`). Use proper HTML entity decoding.

7. **Minimum rows sanity guard:** With 111 product rows currently, a minimum threshold of **10** (as specified in the build plan) is appropriate. This catches catastrophic parse failures while being well below the normal count.
