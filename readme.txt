=== SyncGuard Lite – Supplier Sync Safety for WooCommerce ===
Contributors: syncguardlite
Tags: woocommerce, inventory, stock, supplier, csv, product-import
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview supplier CSV changes, flag risky price updates, and approve WooCommerce product price and stock changes before applying them.

== Description ==

SyncGuard Lite adds a review-and-approve workflow for supplier CSV files. It matches existing products by SKU, previews current and incoming price and stock values, flags unusually large price changes, and prevents invalid rows from being applied.

No product data is changed during preview.

== Installation ==

1. Upload and activate the plugin.
2. Open WooCommerce > SyncGuard Lite.
3. Upload a CSV with sku, price and stock columns.
4. Review the changes and select the rows to approve.

== CSV format ==

A CSV file must contain sku, price and stock headers. Price must be a numeric value in the store currency without a currency symbol. Stock must be a whole number greater than or equal to zero.

== Safety ==

Only users with the manage_woocommerce capability can preview and apply imports. State-changing requests use WordPress nonces. Product data is updated through WooCommerce CRUD methods.

Always test on staging and keep a backup before applying supplier updates.

== Changelog ==

= 1.0.0 =
* Initial release: CSV preview, SKU matching, price anomaly warnings, explicit approval, stock and regular price updates, and import summary history.
