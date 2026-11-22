# Changelog

## 1.2.0

- Hover over a product photo to inspect a detail, or tap or click to open a full-screen gallery. The viewer supports touch panning, zoom, keyboard navigation and returning focus to the selected photo. Hover zoom and the lightbox can each be turned off. [Gallery settings](docs/storefront.md#product-image-gallery)
- Keep the quantity field and Add to cart button side by side on mobile and desktop. [Published theme upgrade notes](README.md#upgrading-to-120)

## 1.1.0

- Highlight featured products and show best sellers ranked by retained paid units. Both lists have Antlers tags. Merchants can enable hand-picked or automatic cross-sells and upgrades per site.
- Edit product descriptions in Bard with headings, links and inline product images. Product detail responses include rendered HTML. CSV and ZIP exports preserve structured descriptions, images and their alternative text.
- Search and filter the catalog as the buyer types, keep the filter form in place, and restore results when navigating browser history. Product quantity sits beside Add to cart. Cart quantity edits update totals automatically. Standard forms remain available.
- Apply and remove coupons and gift vouchers in the checkout order summary. Delivery and payment choices use radio cards. Configure separate terms, privacy and newsletter controls and a payment-obligation label per site. Each new order records the choices and policy version shown to the buyer.

Run `php please sellissimo:install` after updating. The migration updates the catalog index and imports recommendation defaults you explicitly configured. Review published theme overrides and adapt custom checkouts before requiring policy acceptance. [Upgrade notes](README.md#upgrading-to-110)

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
