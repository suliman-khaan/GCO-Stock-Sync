# GCO Supplier Stock Sync — Deployment & Verification Checklist

This document provides the step-by-step procedure for deploying the **GCO Supplier Stock Sync** plugin from development to staging and live production.

---

## Pre-Deployment Verification (Local / Build Environment)

- [x] All 93 automated integration tests pass:
  ```powershell
  & "c:\wamp64\bin\php\php8.2.29\php.exe" "tests/run-tests.php"
  ```
- [x] Production zip packaged via `bin/build-release.ps1` into `release/gco-stock-sync-1.0.0.zip`.
- [x] Verified archive contains NO dev assets (`tests/`, `development-plan/`, `research/`, `bin/`, `.git/`, `phpunit.xml`).
- [x] Text domain catalog `languages/gco-stock-sync.pot` generated with all 164 gettext strings.
- [x] Zero `error_log()` or debug calls left in code.

---

## Phase 7.1 — Staging Deployment

### Step 1: Install & Activate on Staging
1. Log in to the Staging WordPress Admin.
2. Navigate to **Plugins → Add New → Upload Plugin**.
3. Upload `release/gco-stock-sync-1.0.0.zip` and click **Install Now**.
4. Click **Activate Plugin**.
5. Verify database tables were created:
   ```sql
   SHOW TABLES LIKE '%gco_ss%';
   ```
   *Expected: `wp_gco_ss_runs` and `wp_gco_ss_run_items` exist.*

### Step 2: Configure Staging Settings
1. Go to **WooCommerce → Stock Sync**.
2. Set **Enable Stock Sync** to checked.
3. Choose **Schedule Method** (Built-in WP-Cron or Server System Cron).
4. Verify the **Supplier Feed URL** for Highland Outdoors is populated.
5. Click **Test Connection**:
   - Verify green status badge appears showing raw rows and parsed items.
6. Click **Save Settings**.

---

## Phase 7.2 — SKU Mapping Audit

1. Identify the list of live Highland Outdoors products in WooCommerce.
2. Verify that the product SKUs in WooCommerce match the supplier feed `Name` column:
   - Case-insensitive matching is supported (e.g. `HLO-123` matches `hlo-123`).
   - Leading/trailing whitespace is trimmed automatically.
3. Confirm whether any variation SKUs need adjustment.

---

## Phase 7.3 — Manual Sync & Spot-Check (Staging)

1. On the **Settings** page, click **Run Sync Now**.
2. Observe spinner and completion notice (e.g. *Processed X rows, updated Y products*).
3. Navigate to **Sync History & Logs**:
   - Confirm the run status is green **Success**.
   - Click **View Details** on the run.
4. Perform a 10-SKU spot check:
   - Compare 10 products between the WooCommerce store status and the supplier feed inventory.
   - Verify that feed qty > 0 resulted in `In stock`.
   - Verify that feed qty = 0 resulted in `Out of stock`.
   - Verify that WooCommerce stock quantity was **NOT** altered.

---

## Phase 7.4 — Safety Verification (The Critical Fail-Safe Test)

> [!CAUTION]
> **Do not skip this test.** This proves the core safety architecture works in the live WordPress environment.

1. Note the current status of 5 "In stock" products in WooCommerce.
2. In **WooCommerce → Stock Sync → Settings**, change the Feed URL by appending `BROKEN_TEST` to the end of the URL.
3. Click **Test Connection**:
   - Verify red error message is displayed.
4. Click **Save Settings**.
5. Click **Run Sync Now**:
   - Verify the run fails with an error notice.
6. Go to **Sync History & Logs**:
   - Verify the run is logged as **Failed**.
7. Check the 5 "In stock" products:
   - **CONFIRM: All 5 products are STILL "In stock".**
   - **ZERO products were marked "Out of stock".**
8. Restore the valid Feed URL and click **Save Settings**.
9. Click **Run Sync Now**:
   - Verify sync completes successfully and recovery is clean.

---

## Phase 7.5 — Production Deployment

1. **Backup Database**: Perform a full database backup of production before installing any new plugin.
2. **Install & Activate**:
   - Upload `release/gco-stock-sync-1.0.0.zip` via **Plugins → Add New**.
   - Activate the plugin.
3. **Configure Settings**:
   - Go to **WooCommerce → Stock Sync**.
   - Verify feed URL and settings.
   - Click **Test Connection** to confirm connectivity from the production server IP.
   - Click **Save Settings**.
4. **Initial Production Run**:
   - Click **Run Sync Now**.
   - Verify the initial sync completes with status **Success**.
5. **Monitor Cron**:
   - Monitor the next 3 scheduled cron intervals.
   - Confirm new runs appear automatically in the **Sync History & Logs** table.

---

## Phase 7.6 — Client Handover Sign-Off

- [ ] Provide [`docs/client-handover.md`](file:///c:/wamp64/www/gun/wp-content/plugins/gco-stock-sync/docs/client-handover.md) to James.
- [ ] Review how to toggle sync for individual products in **Edit Product → Inventory**.
- [ ] Review what to do if NetSuite rotates the feed hash (contact `johnb@highlandoutdoors.co.uk`).
- [ ] Confirm client has received the handover document and understands daily operations.
