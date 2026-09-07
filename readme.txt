=== GCO Supplier Stock Sync ===
Contributors: sulimankhan
Tags: woocommerce, stock, sync, supplier, inventory
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
WC requires at least: 7.0
WC tested up to: 9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pulls live stock data from supplier feeds and updates WooCommerce product stock status on a configurable schedule.

== Description ==

**GCO Supplier Stock Sync** automatically synchronises product stock status
(in stock / out of stock) from supplier inventory feeds.

= Features =

* Automatic stock status sync on a configurable schedule (30/60/120 minutes)
* Dual scheduling modes: Built-in WP-Cron or Server System Cron / WP-CLI
* In-admin Cron Pattern Reference & Server Setup Guide
* Interactive "Test Connection" button to verify feeds instantly without writing data
* Automated site administrator email alerts upon repeated consecutive feed failures
* Manual "Run Sync Now" from the admin panel with live feedback
* Per-product enable/disable toggle and product list column
* Detailed sync log with per-product outcomes and 30-day auto-pruning
* Safe by design: a failed feed connection **never** marks products out of stock
* Extensible supplier architecture — add new suppliers without modifying core code

= Current Suppliers =

* **Highland Outdoors** — NetSuite SuiteAnalytics web query feed

= Safety First =

The plugin only ever sets stock **status** (in stock / out of stock). It never
writes stock quantities. If a supplier feed fails for any reason, the sync
aborts without touching any products.

== Installation ==

1. Upload the `gco-stock-sync` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to WooCommerce → Stock Sync to configure your supplier feed URL
4. Enable the sync and run a manual test

== Frequently Asked Questions ==

= Does this plugin show stock quantities on the storefront? =

No. The plugin only sets products as "in stock" or "out of stock". Exact
quantities are stored internally for admin diagnostics only.

= What happens if the supplier feed goes down? =

Nothing — that's the whole point. If the feed fails, times out, or returns
unexpected data, the sync aborts without touching any product. Your storefront
continues showing the last known-good status.

= Can I add more suppliers? =

Yes. The plugin uses a supplier abstraction layer. New suppliers can be
registered via the `gco_stock_sync_suppliers` filter without modifying core code.

== Changelog ==

= 1.0.0 =
* Initial release
* Highland Outdoors supplier connector
* Configurable sync interval
* Admin log page with per-product detail
* Per-product sync toggle
* WP-CLI support
