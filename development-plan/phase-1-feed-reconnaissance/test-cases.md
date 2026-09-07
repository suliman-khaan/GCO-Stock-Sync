# Phase 1 — Test Cases (Feed Reconnaissance)

**Phase Type:** Manual Research  
**Testing Approach:** Manual verification checklist — no automated tests  

---

## Test Case Summary

| ID | Test | Priority | Type |
|----|------|----------|------|
| P1-TC01 | Endpoint responds successfully | Critical | Manual |
| P1-TC02 | Response format identified | Critical | Manual |
| P1-TC03 | Column layout matches spec | Critical | Manual |
| P1-TC04 | Section-header rows distinguishable | Critical | Manual |
| P1-TC05 | Product row identification rule defined | Critical | Manual |
| P1-TC06 | Test fixtures created | High | Manual |
| P1-TC07 | Auth requirements documented | High | Manual |
| P1-TC08 | FEED-NOTES.md is complete | Critical | Manual |

---

## Detailed Test Cases

### P1-TC01: Endpoint Responds Successfully

**Priority:** Critical  
**Preconditions:** Internet access, endpoint URL available  
**Steps:**
1. Run `curl -v "<endpoint_url>"` against the NetSuite web query URL
2. Observe the HTTP response status code
3. Check for redirects (3xx) or error pages (4xx, 5xx)

**Expected Result:**
- HTTP 200 OK response
- Response body is non-empty
- No authentication challenge or login redirect

**Failure Action:** STOP — escalate to client to verify URL with Highland Outdoors

---

### P1-TC02: Response Format Identified

**Priority:** Critical  
**Preconditions:** P1-TC01 passed  
**Steps:**
1. Inspect the `Content-Type` response header
2. Open the raw response in a text editor
3. Determine if it's: HTML table, TSV, CSV, JSON, or other

**Expected Result:**
- Format is clearly identified and documented
- One of: HTML `<table>` structure, or tab-delimited text
- Format is parseable with standard PHP tools (`DOMDocument` or string parsing)

**Pass Criteria:** Format documented in FEED-NOTES.md with example markup

---

### P1-TC03: Column Layout Matches Specification

**Priority:** Critical  
**Preconditions:** P1-TC02 passed  
**Steps:**
1. Extract header row from the response
2. Compare each column header to the known spec:
   - Col A: `Qty Available`
   - Col B: `Trade Price`
   - Col C: `Name` (SKU)
   - Col D: `Brand Name`
   - Col E: `Description`
   - Col F: `Internal ID`
   - Col G: `Trade Price` (duplicate)
   - Col H: `Qty Available` (duplicate)
3. Note any deviations (extra columns, different names, different order)

**Expected Result:**
- All 8 columns present with documented headers
- `Name` column confirmed as the SKU field
- Column indices documented for parser implementation

**Pass Criteria:** Column map recorded with exact header text and 0-based indices

---

### P1-TC04: Section-Header Rows Distinguishable

**Priority:** Critical  
**Preconditions:** P1-TC03 passed  
**Steps:**
1. Identify at least 2 section-header rows in the feed (e.g. "Boston Security", "Cabinets")
2. Compare section-header rows to product rows
3. Document distinguishing characteristics:
   - Do section headers have qty = 0?
   - Do section headers have empty price cells?
   - Is the SKU cell different (e.g. only contains a brand name)?
   - Do they span multiple columns?

**Expected Result:**
- Clear rule documented for distinguishing section headers from products
- Rule is deterministic and handles all observed section-header patterns
- At least 3 examples provided: 2 section headers, 1 product

**Pass Criteria:** Filtering rule specified precisely enough to implement in code

---

### P1-TC05: Product Row Identification Rule Defined

**Priority:** Critical  
**Preconditions:** P1-TC04 passed  
**Steps:**
1. Based on analysis, define the exact rule for "this is a valid product row"
2. Verify the rule against every row in the sample feed
3. Count: total rows, product rows, section-header rows, other junk rows

**Expected Result:**
A product row MUST satisfy ALL of:
- [ ] SKU cell is non-empty
- [ ] SKU does not match a known section-header value
- [ ] Qty cell parses as a valid integer
- [ ] (Any additional criteria discovered during analysis)

**Pass Criteria:** Zero false positives and zero false negatives when applied to the full feed

---

### P1-TC06: Test Fixtures Created

**Priority:** High  
**Preconditions:** P1-TC05 passed  
**Steps:**
1. Save `feed-valid.html` — the real successful response (sanitise if needed)
2. Create `feed-empty.html` — response structure with headers but zero product rows
3. Create `feed-garbage.html` — an error page / non-feed HTML

**Expected Result:**
- All three files exist in `tests/fixtures/`
- `feed-valid.html` is a representative sample of the real feed
- `feed-empty.html` has the correct structure but no data rows
- `feed-garbage.html` is clearly not a valid feed response

**Pass Criteria:** Files are usable as HTTP mock responses in Phase 3 tests

---

### P1-TC07: Auth Requirements Documented

**Priority:** High  
**Preconditions:** P1-TC01 passed  
**Steps:**
1. Check if the endpoint requires cookies, session tokens, or HTTP auth headers
2. Test with a clean session (no cookies, no prior authentication)
3. Verify the URL is fully self-contained (all auth is in the URL params)

**Expected Result:**
- The URL works without any cookies, headers, or session state
- OR: auth requirements are documented with the exact headers/cookies needed

**Pass Criteria:** Clear statement in FEED-NOTES.md about auth requirements

---

### P1-TC08: FEED-NOTES.md Is Complete

**Priority:** Critical  
**Preconditions:** All other P1 tests passed  
**Steps:**
1. Review FEED-NOTES.md for completeness
2. Verify it contains all required sections:
   - Response shape
   - Product row identification rule
   - Auth quirks
   - Column mapping

**Expected Result:**
FEED-NOTES.md contains:
- [ ] HTTP response details (status, content-type, encoding)
- [ ] Response format (HTML table / TSV / other)
- [ ] Complete column mapping with exact header text
- [ ] Product row identification rule (precise, implementable)
- [ ] Section-header row examples and filtering rule
- [ ] Auth requirements (or confirmation that none exist)
- [ ] Row counts from the live feed (total rows, product rows, junk rows)
- [ ] Any anomalies or edge cases observed

**Pass Criteria:** A developer can write the parser from FEED-NOTES.md alone without accessing the endpoint
