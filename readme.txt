=== Recurrente for WooCommerce ===
Contributors: recurrente
Tags: payments, payment gateway, recurrente, guatemala, subscriptions
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cards, bank transfers, installments and subscriptions in Guatemala through Recurrente's hosted checkout.

== Description ==

Accept payments in your WooCommerce store with [Recurrente](https://recurrente.com),
the payments platform for Guatemala. The buyer pays on Recurrente's secure hosted
checkout (cards, bank transfers, installments and subscriptions) and WooCommerce
reconciles the order automatically via webhooks.

Features:

* Secure hosted checkout — PCI compliance is handled by Recurrente.
* Itemized checkout: every product and the shipping cost appear as line items.
* Configurable checkout: choose active payment methods, installment options and dynamic (per-method) pricing.
* Customer details are prefilled on the checkout.
* Subscriptions (requires the WooCommerce Subscriptions extension).
* Compatible with High-Performance Order Storage (HPOS).
* Works with both the block-based checkout (WooCommerce 8.3+ default) and the classic shortcode checkout.

== External services ==

This plugin connects to the Recurrente API (https://app.recurrente.com/api) to
create payment sessions. When an order is placed it sends the buyer's billing
details (name, email, phone and address) and the order line items (products,
quantities and amounts) to Recurrente, which are required to prefill and charge
the hosted checkout. Payment notifications (webhooks) are delivered by
Recurrente through Svix (https://www.svix.com/).

* Terms of service: https://recurrente.com/terminos
* Privacy policy: https://recurrente.com/privacidad

== Installation ==

1. Upload the plugin to `/wp-content/plugins/recurrente-for-woocommerce` or install it
   from the WordPress dashboard.
2. Activate it under "Plugins".
3. Go to WooCommerce → Settings → Payments → Recurrente and configure your API keys.
   Saving the settings automatically registers the webhook endpoint in Recurrente —
   no manual webhook setup is needed.

== Screenshots ==

1. Recurrente as a payment method on the WooCommerce checkout (block-based checkout).
2. Plugin settings under WooCommerce → Settings → Payments → Recurrente.

== Frequently Asked Questions ==

= In which countries does it work? =

Recurrente operates in Guatemala. Supported currencies are GTQ and USD.

= Do I need an SSL certificate? =

Yes. WooCommerce and Recurrente require HTTPS to process payments in production.

== Changelog ==

= 0.1.0 =
* Initial release: hosted checkout, itemized checkout, webhooks and subscriptions.
