# Phase 7 — Test Cases (Deployment & Handover)

**Phase Type:** Manual Verification Checklist  
**Testing Approach:** All manual — human verification on staging and production  

---

## Test Case Summary

| ID | Test | Priority | Type | Environment |
|----|------|----------|------|-------------|
| P7-TC01 | Plugin installs on staging without errors | Critical | Manual | Staging |
| P7-TC02 | Plugin activates and creates tables | Critical | Manual | Staging |
| P7-TC03 | Settings page loads and saves | Critical | Manual | Staging |
| P7-TC04 | Manual sync completes successfully | Critical | Manual | Staging |
| P7-TC05 | Log page shows correct run data | High | Manual | Staging |
| P7-TC06 | 10-SKU spot check matches live feed | Critical | Manual | Staging |
| P7-TC07 | Broken feed URL doesn't affect stock | **CRITICAL** | Manual | Staging |
| P7-TC08 | Recovery after fixing feed URL | Critical | Manual | Staging |
| P7-TC09 | Product meta box shows sync status | High | Manual | Staging |
| P7-TC10 | Disabled product unaffected by sync | Critical | Manual | Staging |
| P7-TC11 | Production install clean | Critical | Manual | Production |
| P7-TC12 | First production sync successful | Critical | Manual | Production |
| P7-TC13 | Three automatic cron runs observed | Critical | Manual | Production |
| P7-TC14 | Client handover doc reviewed | High | Manual | N/A |
| P7-TC15 | Client confirms understanding | High | Manual | N/A |

---

## Detailed Test Cases

### P7-TC01: Plugin Installs on Staging Without Errors

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Upload the release zip to staging via wp-admin → Plugins → Add New → Upload
2. Check for PHP errors during upload
3. Verify plugin appears in the plugins list

**Expected Result:**
- Plugin listed with correct name, version, description
- No PHP errors or warnings in error log
- No conflict with existing plugins

**Pass Criteria:** Plugin visible in plugin list, zero PHP errors

---

### P7-TC02: Plugin Activates and Creates Tables

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Click "Activate" on the plugin
2. Check wp-admin for any error notices
3. Verify custom tables exist (via phpMyAdmin or WP-CLI):
   ```bash
   wp db query "SHOW TABLES LIKE '%gco_ss%';"
   ```

**Expected Result:**
- Plugin activates without errors
- `wp_gco_ss_runs` table exists
- `wp_gco_ss_items` table exists
- Admin notice shows if this is first activation

---

### P7-TC03: Settings Page Loads and Saves

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Navigate to WooCommerce → Stock Sync (or equivalent menu location)
2. Verify all settings fields render correctly
3. Enter the Highland Outdoors feed URL
4. Set sync interval to 30 minutes
5. Enable sync globally
6. Click Save
7. Reload the page

**Expected Result:**
- All settings persisted after page reload
- Feed URL displayed correctly
- Interval shows 30 minutes
- Global enable is checked

---

### P7-TC04: Manual Sync Completes Successfully

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Click the "Run sync now" button on settings page
2. Wait for AJAX response
3. Check the response message

**Expected Result:**
- Sync completes with status "success"
- Response shows: rows fetched count, products updated count
- No errors in the response

---

### P7-TC05: Log Page Shows Correct Run Data

**Priority:** High  
**Environment:** Staging

**Steps:**
1. Navigate to the log page
2. Find the run from P7-TC04
3. Verify run-level data
4. Click into the run to see item details

**Expected Result:**
- Run row shows: correct time, supplier name, status (green "success"), row count, update count
- Item rows show: SKU, matched product (clickable), supplier qty, action (updated/unchanged/unmatched)
- No items with unexpected actions

---

### P7-TC06: 10-SKU Spot Check Against Live Feed

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Open the Highland Outdoors feed URL directly in a browser
2. Pick 10 SKUs from the feed
3. For each SKU:
   a. Note the feed qty
   b. Find the product in WooCommerce
   c. Compare: expected stock status (qty > 0 = instock, qty = 0 = outofstock)
   d. Compare: `_gco_ss_last_qty` in product meta
4. Record all 10 comparisons

**Expected Result:**
| SKU | Feed Qty | Expected Status | Actual Status | `_gco_ss_last_qty` | Match? |
|-----|----------|----------------|---------------|---------------------|--------|
| ... | ... | ... | ... | ... | ✓/✗ |

All 10 must match.

---

### P7-TC07: Broken Feed URL Doesn't Affect Stock ⚠️

**Priority:** CRITICAL — This is the #1 safety test on a real environment  
**Environment:** Staging

**Steps:**
1. Note current stock status of 5 instock products
2. Change the feed URL to something invalid (e.g. add `BROKEN` to the hash)
3. Click "Run sync now"
4. Check all 5 products' stock status

**Expected Result:**
- Sync run status = "failed"
- ALL 5 products remain "instock"
- ZERO products changed
- Error message in run log explains the failure
- **If even one product changes status, this is a CRITICAL BUG — do not go live**

---

### P7-TC08: Recovery After Fixing Feed URL

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Fix the feed URL back to the correct value
2. Click "Run sync now"
3. Check sync completes

**Expected Result:**
- Sync succeeds
- Products update correctly based on feed data
- System recovers cleanly from previous failure

---

### P7-TC09: Product Meta Box Shows Sync Status

**Priority:** High  
**Environment:** Staging

**Steps:**
1. Edit a product that was synced
2. Go to the Inventory tab
3. Check the sync meta box

**Expected Result:**
- "Sync stock from supplier" checkbox is visible
- Last supplier qty is displayed (read-only)
- Last sync time is displayed

---

### P7-TC10: Disabled Product Unaffected

**Priority:** Critical  
**Environment:** Staging

**Steps:**
1. Edit a product, uncheck "Sync stock from supplier"
2. Save the product
3. Run a manual sync
4. Check the product's stock status

**Expected Result:**
- Product stock status unchanged
- In the run log, product shows action = "skipped"

---

### P7-TC11: Production Install Clean

**Priority:** Critical  
**Environment:** Production

**Steps:**
1. Take a full database backup before proceeding
2. Upload and activate plugin on production
3. Configure settings
4. Verify no PHP errors

**Expected Result:**
- Clean installation
- No errors
- Settings saved correctly

---

### P7-TC12: First Production Sync Successful

**Priority:** Critical  
**Environment:** Production

**Steps:**
1. Run manual sync on production
2. Verify log shows success
3. Spot-check 3 products

**Expected Result:**
- Sync completes
- Products reflect correct stock status

---

### P7-TC13: Three Automatic Cron Runs Observed

**Priority:** Critical  
**Environment:** Production

**Steps:**
1. Wait for 3 scheduled sync intervals to pass
2. Check log page for 3 automatic runs
3. Verify all 3 have status = "success"

**Expected Result:**
- 3 runs with correct timestamps (spaced by the configured interval)
- All successful
- Product stock statuses accurate

---

### P7-TC14: Client Handover Doc Reviewed

**Priority:** High

**Steps:**
1. Review `docs/client-handover.md` for completeness
2. Verify it covers all required topics:
   - [ ] Where settings are
   - [ ] How to read the log
   - [ ] How to disable sync for one product
   - [ ] What to do if feed URL breaks
   - [ ] How real cron works
   - [ ] How to use Test Connection

**Expected Result:**
- Document is complete, clear, and written for a non-technical audience

---

### P7-TC15: Client Confirms Understanding

**Priority:** High

**Steps:**
1. Walk James through the handover document
2. Show him the settings page, log page, and product meta box
3. Ask if he has questions
4. Confirm he knows how to contact Highland Outdoors if the feed breaks

**Expected Result:**
- Client confirms understanding
- Client can navigate settings and logs independently
- Client knows the escalation path for feed issues
