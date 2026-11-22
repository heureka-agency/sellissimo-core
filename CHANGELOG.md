# Changelog

## 1.0.1

- Store Settings sections can link directly to an addon's own editor. Existing section bookmarks take authorized staff to the editor for their selected site.

- Extensions can capture checkout facts inside order placement, request restricted buyer fields, and add permission-aware links to an order.
- Stored pricing includes the applied tax rate and inclusion setting for items, shipping and fees. New parcels record whether payment had settled at dispatch.
- Applied amendments and late refund reversals emit after-commit events. Run `php please sellissimo:install` to add the new columns to an existing store.

## Initial release

- Sell physical products, digital downloads and gift vouchers from a Statamic catalog. Buyers can use the bundled storefront or a custom Antlers checkout.
- Manage orders, inventory, shipments, returns and refunds in the Control Panel. Configure currencies, tax, payment methods and shipping for each store.
- Accept payments with Stripe or Mollie and quote delivery with Flat Rate Shipping. Extend checkout through Sellissimo’s payment, shipping and pricing contracts.

Back up the commerce database, site content, configuration and private downloads before updating an existing installation. Update Core and the installed extensions together, run `php please sellissimo:install` and rebuild the application caches. [Upgrade guide](https://sellissimo.heureka-kreativ.hu/docs/upgrades)
