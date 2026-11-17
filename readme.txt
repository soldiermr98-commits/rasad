=== Rasad (رصد) ===
Contributors: rasad
Tags: analytics, statistics, privacy, jalali
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local, cookie-free WordPress analytics with Persian RTL reporting and Jalali date selection.

== Description ==

Rasad collects pageviews on your server using buffered collection and summary tables. Reports include pages, referrals, campaigns, search terms, audience technology, geography, live visitors, and currency-separated WooCommerce purchase summaries. Includes CSV exports, a dashboard widget, and post/page views columns.

WooCommerce purchase deduplication uses a durable order ledger and InnoDB transactions. Revenue represents initial paid purchases, without subsequent refund adjustments. Classic checkout-start is tracked; Blocks order attribution is supported, while Blocks checkout-start is not currently counted separately.

See README.md for measured performance, privacy settings, Nginx protection, queue crash-delivery limits and test commands.

== Installation ==

1. Install the rasad directory in wp-content/plugins.
2. Activate and open Rasad settings to inspect buffer and cron health.
3. Use a server cron on low-traffic sites to run wp rasad aggregate every minute.

== Changelog ==

= 1.1.0 =
* Complete dashboard reports, local Jalali picker and streamed capability-gated CSV.
* Add durable WooCommerce purchase deduplication and attributed summaries.
* Correct concurrent buffer handling and remove destructive queue size trimming.
* Correct direct SHORTINIT collection, cleanup and Jalali date conversion.
* Add repeatable unit, concurrency and local integration tests.
