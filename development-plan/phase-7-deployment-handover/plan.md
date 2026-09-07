# Phase 7 — Deployment & Handover

**Type:** Operations + Documentation  
**Goal:** Safe deployment to production with verified behaviour and complete client handover.  
**Estimated Effort:** 4–6 hours  
**Prerequisite:** Phase 6 complete (all review gates passed)

---

## Tasks

### 7.1 — Staging Deployment
1. Install plugin on **staging first** — never straight to live
2. Activate plugin
3. Configure settings: enable sync, set feed URL, set interval
4. Verify all admin pages load without errors

### 7.2 — SKU Mapping Verification
1. Confirm with client which of the ~70 products are live
2. Check that product SKUs in WooCommerce match the feed's `Name` column exactly
3. Document any mismatches for manual correction

### 7.3 — Manual Sync Test (Staging)
1. Run manual sync via the "Run sync now" button
2. Verify sync completes with status = 'success'
3. Check the log page for correct item-level data
4. Compare a sample of 10 SKUs against the live feed by hand

### 7.4 — Safety Verification
1. **Deliberately break the feed URL** (change the hash to garbage)
2. Run sync again
3. Confirm: run status = 'failed', ZERO products changed stock status
4. Fix the URL back
5. Run sync — confirm recovery works

### 7.5 — Go Live
1. Install plugin on production
2. Configure with correct feed URL
3. Run first manual sync
4. Monitor the first 3 automatic cron runs

### 7.6 — Real-Cron Setup (Recommended)
If the client wants exact timing:
```bash
*/30 * * * * wget -q -O - https://guncabinetsonline.co.uk/wp-cron.php?doing_wp_cron
```

### 7.7 — Client Handover Document
Write for James covering:
- Where the settings are (WooCommerce → Stock Sync)
- How to read the log page
- How to disable sync for one product
- What to do if the feed URL stops working:
  > Contact johnb@highlandoutdoors.co.uk for a fresh URL, then paste it into Settings
- How real cron works if they want exact timing
- How to use the "Test Connection" button

---

## Files Created in This Phase

```
docs/
├── client-handover.md
└── deployment-checklist.md
```

---

## Quality Gate

- Human verification of all deployment steps
- Client sign-off on handover document
- 3 successful automatic sync runs observed on production

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| SKU mismatches on production | Products not syncing | Manual SKU audit before go-live |
| Feed URL changes after deploy | Sync fails silently | Email alerting (Phase 6) |
| WP-Cron not firing | Sync stale | Real-cron recommended |
| Client can't self-service | Ongoing support needed | Detailed handover doc |
