# Phase 1 — Feed Reconnaissance

**Type:** Research (no plugin code)  
**Goal:** Know exactly what the Highland Outdoors endpoint returns before writing a parser.  
**Estimated Effort:** 2–4 hours  
**Prerequisite:** None

---

## Tasks

### 1.1 — Fetch Raw Feed Response
- `curl` the NetSuite web query endpoint
- Save the raw response to `research/feed-sample.html`
- Note all HTTP response headers (Content-Type, encoding, etc.)

### 1.2 — Determine Response Format
- Is it an HTML `<table>`?
- Is it tab-separated values (TSV)?
- Is it something else entirely?
- Does it require auth headers, cookies, or session tokens?

### 1.3 — Validate URL Parameters
- Confirm the `email` param works as a plain GET value
- Check if `compid`, `entity`, `role`, `cr`, `hash` are all static
- Determine if the hash has an expiry or rotation policy

### 1.4 — Map Data Structure
- Note exact header row text
- Count how many junk/header rows precede actual data
- Document how section-header rows (e.g. "Boston Security", "Cabinets") are distinguishable from product rows
- Verify column layout matches the known spec:

| Col | Header | Notes |
|-----|--------|-------|
| A | Qty Available | integer |
| B | Trade Price | may be blank on accessories |
| C | Name | **this is the SKU** |
| D | Brand Name | e.g. Boston Security / Buffalo River |
| E | Description | long text, unused |
| F | Internal ID | NetSuite ID |
| G | Trade Price | duplicate column |
| H | Qty Available | duplicate column |

### 1.5 — Create Test Fixtures
Save to `tests/fixtures/`:
- `feed-valid.html` — real successful response (sanitised if needed)
- `feed-empty.html` — response with headers but zero product rows
- `feed-garbage.html` — an error page / HTML that isn't the feed

---

## Deliverable

`research/FEED-NOTES.md` documenting:
1. Response shape (HTML table structure, row/column layout)
2. The exact rule for identifying a valid product row vs. a section header
3. Any auth quirks or URL parameter notes
4. Column-to-field mapping confirmed against live data

---

## Blocking Condition

> **If the endpoint doesn't resolve or the hash has expired**, STOP and flag it to the client. They need to contact Highland Outdoors (johnb@highlandoutdoors.co.uk) for a fresh URL. **Do not build a parser against guesses.**

---

## Quality Gate

- **Human review** of `FEED-NOTES.md`
- No automated gates this phase
- Sign-off required before proceeding to Phase 2

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Hash expired | Blocker — cannot proceed | Contact client immediately |
| Response format differs from expectation | Rework parser design | Document actual format, adjust Phase 3 plan |
| Feed requires session auth | Major complexity increase | Document auth flow, may need cookies/tokens |
| Column layout doesn't match .xlsx | Parser logic changes | Map actual columns in FEED-NOTES.md |
