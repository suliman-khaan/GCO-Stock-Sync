# GCO Supplier Stock Sync — Client Handover Guide

Welcome to the **GCO Supplier Stock Sync** plugin documentation. This guide explains how the automatic stock synchronization works, how to configure and monitor feeds, how to control stock sync on individual products, and what to do if a supplier feed changes.

---

## 1. Quick Summary & The Golden Safety Rule

The **GCO Supplier Stock Sync** plugin connects Gun Cabinets Online directly to supplier inventory feeds (starting with **Highland Outdoors**) to automatically update stock availability in your store.

### The Golden Safety Rule:
> **A failed supplier connection NEVER touches your WooCommerce products.**  
> If Highland Outdoors’ server is down, times out, returns an error, or changes their feed format, the plugin immediately aborts the run without touching a single product. Your products remain in their last known status. Products will **never** be accidentally set "Out of Stock" because of a supplier network glitch.

### Key Points to Know:
- **Stock Status Only**: The plugin only updates whether a product is **In stock** or **Out of stock**. It **never** alters your manual stock quantities or inventory counts.
- **Selective Matching**: The plugin only updates products whose WooCommerce SKU matches an item in the supplier feed. Products not in the feed (or from other suppliers) are completely ignored.
- **Fail-Safe Alerting**: If the feed fails 3 times in a row, the site administrator automatically receives an email notification with the exact error details.

---

## 2. Accessing the Plugin in WordPress

Log in to your WordPress admin panel and navigate to:
**WooCommerce → Stock Sync**

The plugin interface contains two main tabs:
1. **Settings**: Configuration, live connection test, and status overview.
2. **Sync History & Logs**: Detailed logs of every sync run and individual product outcome.

---

## 3. Settings & Configuration

### General Settings
- **Enable Stock Sync**: Master switch. Turn this on to enable automatic scheduled synchronizations.
- **Schedule Method**:
  - **Built-in WP-Cron (Default)**: Runs automatically in the background when visitors browse your website. Ideal for standard operation.
  - **Server System Cron / WP-CLI (Recommended for high reliability)**: Disables traffic-triggered cron in favor of your web server’s system crontab. Sync runs execute at exact times regardless of traffic.
- **Sync Interval**: How frequently the feed is checked when using WP-Cron (30 minutes, 60 minutes, or 2 hours). Default is **60 minutes**.
- **Delete Data on Uninstall**: If checked, drops the sync database tables when the plugin is uninstalled. Leave unchecked to preserve history if upgrading or reinstalling.

### Highland Outdoors Settings
- **Feed URL**: The secure HTTPS URL provided by Highland Outdoors (NetSuite SuiteAnalytics Web Query).
- **Minimum Rows Guard**: Safety threshold (default: 100 rows). If Highland Outdoors accidentally returns an empty or truncated page with fewer rows, the sync safely aborts.

### The "Test Connection" Button
Next to the **Supplier Feed URL** input, you will find the **Test Connection** button:
1. Click **Test Connection** at any time.
2. The plugin will query the feed in real-time without modifying any products or logging a run.
3. If successful, you will see a green badge showing the number of rows and items detected (e.g. `Connection successful! Feed responded with 4,495 raw rows`).
4. If failed, a red error badge will display the exact reason (e.g., HTTP 404, invalid URL, or NetSuite error).

---

## 4. How to Read Sync History & Logs

Click the **Sync History & Logs** tab at the top of the page.

### Run Overview
Each row in the table represents a sync execution:
- **Status Badges**:
  - <span style="color: #1a7f37; font-weight: bold;">Success</span>: Feed was fetched and all eligible products were evaluated.
  - <span style="color: #cf222e; font-weight: bold;">Failed</span>: Feed connection or parsing failed. **Zero products were modified.**
  - <span style="color: #9a6700; font-weight: bold;">Partial</span>: Feed was read, but one or more individual product items encountered an issue (the rest succeeded).
  - <span style="color: #57606a; font-weight: bold;">Skipped</span>: Run was skipped (e.g. another sync was already running or sync is disabled).
- **Rows Processed**: Total lines parsed from the supplier feed.
- **Products Updated**: Count of WooCommerce products whose stock status actually changed (e.g., from Out of Stock to In Stock).

### Viewing Run Details
Click **View Details** on any run to see the SKU-by-SKU breakdown:
- **SKU**: The supplier SKU.
- **Product Title**: Clickable link to edit the matched WooCommerce product.
- **Supplier Qty**: Exact inventory count returned in the supplier feed.
- **Status Change**: Shows previous stock status and new stock status (e.g. `outofstock → instock`).
- **Action**:
  - `UPDATED`: Status was toggled to reflect feed inventory.
  - `UNCHANGED`: Status already matched feed inventory; timestamp updated.
  - `SKIPPED`: Product has manual sync disabled in its settings.
  - `UNMATCHED`: SKU in feed does not exist in your WooCommerce store.

*Note: Logs older than 30 days are automatically cleaned up to keep your database fast.*

---

## 5. Controlling Sync on Individual Products

You can selectively disable automatic sync for any product (for example, if you hold physical stock in your own warehouse and don't want the supplier feed to set it out of stock):

1. Go to **Products → All Products** and click **Edit** on any product.
2. In the **Product Data** box, click the **Inventory** tab on the left.
3. Locate the **Supplier Stock Sync** section:
   - **Sync stock from supplier**: Check to enable (default) or uncheck to disable sync for this product.
   - **Last Supplier Qty**: Read-only display of the last quantity reported in the feed.
   - **Last Synced**: Date and time of the last sync check.
4. Click **Update** to save the product.

### For Variable Products:
Each variation under the **Variations** tab contains its own **Sync stock from supplier** checkbox, allowing granular control per variant (e.g. per size or colour).

### Product List Quick View:
On the main **Products** list in WordPress admin, look for the **Stock Sync** column:
- A green checkmark indicates sync is active.
- A grey dash indicates sync is disabled.
- Hover over the icon to see the last recorded supplier quantity and sync date.

---

## 6. What to Do If the Feed Stops Working

Supplier feeds can occasionally break if NetSuite changes the query hash, the URL expires, or Highland Outdoors updates their reporting system.

### Automated Email Alert
If 3 consecutive sync runs fail, the plugin will automatically email the site administrator:
- **Subject**: `[Gun Cabinets Online] Stock Sync Alert: Repeated sync failures detected`
- The email includes the exact error message and a direct link to the logs.
- The alert emails once every 24 hours to avoid inbox flooding.

### Step-by-Step Resolution:
1. Log in to WordPress and navigate to **WooCommerce → Stock Sync**.
2. Click **Test Connection**. Note the error message displayed.
3. If the message indicates HTTP 401 (Unauthorized), HTTP 404 (Not Found), or "Hash expired":
   - Email Highland Outdoors support:
     > **Contact**: John B.  
     > **Email**: `johnb@highlandoutdoors.co.uk`  
     > **Message**: *"Hi John, our live stock feed URL for Gun Cabinets Online appears to have expired or rotated. Could you please provide the latest SuiteAnalytics Web Query CSV URL for our account? Thank you."*
4. Once John replies with the new feed URL:
   - Paste the new URL into the **Supplier Feed URL** field.
   - Click **Test Connection** to confirm it turns green.
   - Click **Save Settings**.
   - Click **Run Sync Now** to immediately synchronize stock.

---

## 7. Server Crontab & WP-CLI Setup (Optional for Web Host)

If you or your hosting provider wish to run the sync on an exact schedule rather than relying on visitor traffic:

1. In **WooCommerce → Stock Sync → Settings**, set **Schedule Method** to **Server System Cron / WP-CLI**.
2. In your web hosting control panel (e.g. cPanel → **Cron Jobs**), add one of the following commands:

### Recommended: WP-CLI Method (Fastest)
Run every hour on the hour:
```bash
0 * * * * wp gco-stock-sync run --path="/path/to/your/wordpress" >/dev/null 2>&1
```

### Alternative: Web Trigger (cURL)
```bash
0 * * * * curl -s -L "https://guncabinetsonline.co.uk/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

---

## 8. Summary Checklist for Daily Store Operations

| Action | Where | Frequency |
|---|---|---|
| Check overall sync health | **WooCommerce → Stock Sync** (Status Panel) | Weekly |
| Run an on-demand sync | Click **Run Sync Now** | Whenever desired |
| Exclude product from supplier sync | **Edit Product → Inventory tab** → Uncheck sync box | As needed |
| Update feed URL after supplier rotation | **WooCommerce → Stock Sync → Feed URL** | If notified by email |
