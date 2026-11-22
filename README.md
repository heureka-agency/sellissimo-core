# Sellissimo for Statamic

Sell physical products, private downloads, and mixed orders on a Statamic 6 site. Products live in Statamic collections. Orders, carts, available stock, refunds, and download blocks use a Laravel database connection. The core and each shipping or payment extension install independently.

## Licensing

The first 100 buyers pay USD 49 for one production site. The regular price is USD 99. A Core license includes one year of support and updates. Stripe, Mollie and Flat Rate Shipping are free extensions for the licensed site. Renewal is optional at USD 49 per year for every buyer. You may continue using covered versions after renewal lapses. [License agreement](LICENSE) · [Site licensing guide](docs/licensing.md)

Local development and staging are free. For help with your installation, email [the Sellissimo team](mailto:hello@heureka-kreativ.hu).

## Install Sellissimo

Use PHP 8.3 or later, the Intl and ZIP extensions, and PDO for your commerce database. The default SQLite database requires a persistent writable database directory. A site deployed to multiple application servers should use a shared MySQL or PostgreSQL connection and shared private file storage.

For the first-order walkthrough, install Core and Flat Rate Shipping from your Statamic site. The walkthrough uses the built-in pay-on-delivery gateway.

```sh
composer require sellissimo/core sellissimo/shipping-flat-rate
php please sellissimo:install
```

Open **Store → Settings → Checkout and customers** and enable the bundled storefront. In **Payments**, enable **Pay on delivery** for the selected site. Publish a physical USD 18 product, add stock, and enable collection on delivery on the USD 5 US shipping service in **Store → Settings → Shipping and pickup**. Add one item and check out with a US delivery address. The expected total is USD 23 before any tax. [Illustrated first-order walkthrough](https://sellissimo.heureka-kreativ.hu/docs/quickstart)

To take online payments, install the matching `sellissimo/stripe` or `sellissimo/mollie` package alongside Core and configure its credentials. Each service requires its own merchant account and charges its own processing fees. For an offline installation, register the directory containing the supplied release ZIPs with `composer config repositories.sellissimo artifact /absolute/path/to/release-zips` before running the same install command.

### Compatibility

| Component | Package requirement |
| --- | --- |
| Statamic | 6.x |
| PHP | 8.3 or later in the 8.x series, with Intl, ZIP and the database PDO driver |
| Laravel | 12.40 or later in 12.x, or 13.x, subject to the framework's PHP requirement |
| Commerce database | SQLite, MySQL or PostgreSQL |

Multisite stores, separate staff roles and permissions, and the bundled buyer registration form require Statamic Pro. Guest checkout works on a single-site Statamic Core installation.

To upgrade an earlier build, update the installed Sellissimo packages together with Composer, then run `php please sellissimo:install`. Existing draft links and order receipts are preserved.

Omit extensions you do not need. To connect another collection during initial setup, use `php please sellissimo:install --collection=merchandise`. The installer adds a dedicated Sellissimo product blueprint. Existing entries can keep their own blueprint. Import `sellissimo::product` into an existing blueprint to expose its commerce fields to editors. Repeated installation preserves edited blueprints and runs pending database migrations.

The installer writes `config/sellissimo.php`. Choose each site’s selling currency in **Store → Settings → Store details and currency**. Existing configuration supplies the inherited defaults. Configure `SELLISSIMO_CONNECTION` before installation to use an existing Laravel connection, or `SELLISSIMO_SQLITE_PATH` for a different SQLite location. Enable the optional storefront with `SELLISSIMO_STOREFRONT=true`. Choose its reserved URL prefix with `SELLISSIMO_PREFIX=shop`. After changing these settings, clear configuration and route caches and rerun the installer for collision checks. Templates load from the package. Override individual files in `resources/views/vendor/sellissimo`.

Configure Laravel mail and the Laravel scheduler in production. Every five minutes the addon checks pending payments, retries pending refunds, and sends outstanding receipts, collection-on-delivery order confirmations and requested shipment emails. Buyers who choose collection on delivery receive the amount due and a signed order link at checkout. Recording collection sends their paid receipt. Run `php artisan sellissimo:reconcile` to trigger the same recovery manually. The scheduler must use the web application's configuration, database, mail, private disk, timezone, and canonical APP_URL.

## Store operations

Use **Store → Catalog & inventory → Catalog import and export** for a preview of product and variant changes. **Inventory → Locations and history** shows transfers, balances and the stock history for an SKU. Open address books under **Customers**, return requests under **Orders → Returns**, and period totals under **Sales reports**. Run `php please sellissimo:install` after updating to install the new storage tables. [Merchant guide and CSV format](docs/store-operations.md)

## Product editor

The default Products blueprint has four tabs. Existing products can continue selling with their current SKU and price.

| Tab | What merchants can edit |
| --- | --- |
| Product details | Title, short description, Bard description with inline images, ordered image gallery with asset alternative text, brand, product type, categories, tags and specifications. |
| Pricing and variants | SKU, regular and sale prices in each currency, sale dates, internal item cost, barcode, manufacturer part number, unit pricing, tax class, private download and buyer personalization. Variants can supply their own identifiers, prices, costs, attributes, image and package measurements. |
| Shipping | Physical package weight in kilograms, dimensions in centimetres, country of origin and customs code. Mark a service as virtual to sell it without stock or shipping. |
| Merchandising | Featured status, related products, complementary products, product upgrades, alternative product group, page title and search description. |

Run `php please sellissimo:install` after upgrading. It adds the **Product images** asset container, **Product categories** and **Product tags** taxonomies. It upgrades the original generated blueprint to the four-tab layout and preserves merchant-edited layouts. An existing `sellissimo::product` import includes all four groups. For custom layouts, import each group separately with `sellissimo::product_content`, `sellissimo::product_sales`, `sellissimo::product_shipping` and `sellissimo::product_discovery`.

Sale dates include the first and last day in the application timezone. Leave dates empty for an ongoing sale. Unit prices use the current selling price divided by the quantity per pack. Checkout charges the per-pack selling price. Upload public images in **Assets → Product images** and keep purchased files on the private downloads disk.

Draft orders can prefill an account’s optional `sellissimo_billing_address` and `sellissimo_shipping_address` arrays on its Statamic profile. Otherwise they reuse the corresponding address from its latest linked order on the selected site. An editor can change either address for this order.

## Merchandising and buyer checkout

Mark a product **Featured** to include it in the catalog's featured section. The popular section ranks products by paid units retained in the last 90 days. Enable cross-sells or upgrades per site, select products in display order, and choose whether Sellissimo should fill spare places automatically. Both catalog sections also work on custom Antlers pages. [Featured and popular tags](docs/storefront.md#featured-and-popular-products) · [Recommendation settings](docs/store-settings.md#configure-product-recommendations)

The bundled shop updates search results, filters and cart quantities as buyers edit them. Checkout shows delivery and payment choices as cards and keeps coupon and gift voucher controls in the order summary. Stores can request terms acceptance, acknowledgement of a privacy notice and a separate, optional newsletter subscription. [Checkout policies and integrations](docs/checkout-extensions.md#checkout-policies-and-newsletter-opt-in)

## Operate the shop

- Create and publish a Sellissimo product entry. Enter a unique SKU and a price such as 12.50 in the USD field. Prices are integers in currency minor units. For variants, add a row per option, including its own SKU and prices. Variants replace the simple SKU. Use the same SKU on translations that share stock. A missing site price removes the item from that site's catalog.
- For downloads, place the file in `storage/app/sellissimo/private` or configure a dedicated private disk. Choose the file in the product’s **Private download** field. A variant can have its own file. Digital lines require no stock or delivery.
- Grant staff the **Manage Sellissimo store** permission and Control Panel access. In **Store → Catalog & inventory → Inventory**, select **Add stock level** and choose a physical product or variant by name. Search tracked stock by name or SKU, or open **Out of stock**. Use **Adjust** to change the quantity available to sell. Outstanding reservations have already been subtracted from this figure. Expired checkout restores inventory. Use Receive a return on the order to add sellable units back to stock.
- Choose a product’s **Tax class** from the classes in **Store → Settings → Taxes and pricing**. Existing classes defined in configuration are also available.
- Configure country-specific tax classes and discount codes in `config/sellissimo.php`. Tax rates use basis points. For example, `'taxes' => ['US' => ['standard' => 750]]` adds 7.5% to discounted standard-class merchandise for a US destination. Tax is exclusive, rounded per line. Under **Store → Settings → Taxes and pricing → Tax calculation settings**, choose the address for physical goods and an optional delivery tax class per site. Digital goods use billing. Percentage discounts use `'discounts' => ['WELCOME' => ['basis_points' => 1000]]`. Fixed discounts use `['amounts' => ['USD' => 500]]`. One code applies per cart.
- Publish delivery settings with `php artisan vendor:publish --tag=sellissimo-flat-rate-config`. The example US rate is USD 5.00. Set your own destinations and amounts before enabling sales. Configure Stripe using its package README.
- In **Store → Orders**, search by order number, customer name or email, filter by payment status, or open **Awaiting shipment**. **Export → Export CSV** downloads every matching order across pages. The original total and settled refunds are separate columns in each order’s currency.
- Open an order to add tracking, resend its receipt, or manage download access. Under **Fulfillment**, open **View packing slip** to print physical items for a paid or previously shipped order. Customize the slip in `resources/views/vendor/sellissimo/packing-slip.blade.php`.
- Under **Order activity**, staff can leave a private note for colleagues. Payment outcomes, recorded shipments and refund outcomes appear there automatically after upgrade. Older orders retain their original placement date. Notes stay in the Control Panel and are excluded from emails, buyer receipts, CSV exports and packing slips.
- Choose **Issue refund** to review and confirm a refund for an item, shipping, or the remaining balance. Enter partial refunds in the order currency. A fully refunded digital line loses access, including links issued earlier. Removing a manual download block still requires a paid, eligible line.
- Guests receive an expiring receipt link by email. Signed-in customers can access their order history and download library at `/api/sellissimo/v1/account/orders`. Receipts retain the prices, descriptions, tax, discount, currency, delivery cost, and address from checkout.

## Pricing, customers and fulfillment

- In **Store → Discounts & vouchers → Discounts**, choose **Automatic store-wide offer** to run a sale without a code, or **Coupon code** for an offer a buyer enters. Both support fixed amounts, percentages, free shipping, a currency, minimum merchandise spend and inclusive dates in the application timezone. An automatic offer applies to eligible baskets across storefronts using its currency. Automatic free shipping requires physical items.
- A valid entered code takes precedence. Select specific products or SKUs and enable combination to stack eligible offers. Automatic offers apply in priority order, with ties broken by name and identifier. Each applied amount is saved on the order and receipt.
- For a coupon, leave **Total uses allowed** or **Uses per customer** blank for unlimited use, or enter a positive whole number. The Discounts list shows uses so far. Guest customers are matched by trimmed, case-insensitive checkout email. Signed-in purchases also count against the account ID and account email. Distinct guest emails can represent separate buyers.
- Creating an order reserves one use, including orders awaiting payment or cash collection. Quotes and drafts leave all uses available. Expired checkouts and cancelled unpaid orders release the use. A paid or subsequently refunded order keeps it. Adding or lowering a limit counts existing orders that used the code, including purchases made before the upgrade.
- Configuration-defined coupons accept the same optional limits, for example `'WELCOME' => ['basis_points' => 1000, 'usage_limit' => 500, 'per_customer_limit' => 1]`.
- In **Taxes → Classes**, choose a handle to use on the product blueprint and whether catalog prices include tax. In **Zones**, select the class, country and rate. Blank region and postcode fields match the whole country. Regions match without case sensitivity. Postcodes accept an asterisk wildcard. Matching zone rates add together for a class. Destinations with no matching zone for that class use the configured country rate. Tax rounds half up once per discounted line in the currency’s smallest unit.
- The product blueprint’s **Personalization** grid accepts text fields with a label, required setting and length limit. Sellissimo generates a stable field identifier from the label when first saved. Different engravings of the same SKU have separate receipt, fulfillment and refund lines and draw from the same inventory.
- Login combines a guest cart with the active account cart for the same site and currency. Signed-in buyers recover that cart across devices. In **My orders and downloads**, a buyer can enter a guest order ID and confirm a 30-minute email link while signed into the matching account.
- In **Draft orders**, choose products, quantities, personalization, customer email and destination. Use **Select customer** to find a registered Statamic account, including a first-time buyer, or reuse the details from a previous guest order on this site. To create a guest order, type the buyer’s name and email directly. Only explicitly selecting an account links the resulting order to it. Enter the full billing address and clear **Same as billing address** for a separate delivery recipient. The shipping section is available as soon as you open the draft. Enter delivery instructions and choose a quoted method, or select **Set a custom delivery rate** to enter the service name and charge in the draft currency. The order summary recalculates as you edit. Choose **Save draft** at any point. When all details and stock are ready, choose **Send payment link** to email a seven-day link. Saving new edits or cancelling closes the previous link. Buyers review current prices and place the order to reserve stock. Changed prices require a fresh review.
- To accept payment at delivery, add `pay_on_delivery` to the configured payments. Physical and mixed orders enter **Awaiting collection**. Staff can pack and ship before collection. Choose **Record payment collected** to issue the receipt and unlock eligible downloads. Cancelling an unshipped unpaid order releases stock once. After returning money outside Sellissimo, choose **Record manual refund** with the amount, reason and payment reference.
- Select **Email tracking to customer** at shipment or **Email shipment details** later. The email includes shipped status, tracking number and receipt link. Customize it in `resources/views/vendor/sellissimo/mail/shipped.blade.php`. The scheduler retries requested emails after delivery failures.

### Dashboard widgets

**Store activity** appears by default for staff with access to orders or sales. Its period menu switches between 7, 30, and 90 days. Order staff see the five most recent orders in the period. Sales staff see new customers, returning customers, and settled refunds.

To add **Low stock**, **Awaiting shipment**, or **Lifetime sales**, add the corresponding entry to `widgets` in `config/statamic/cp.php`. Each widget uses the staff member’s store permissions and site access.

```php
['type' => 'sellissimo_low_stock', 'threshold' => 5, 'width' => 50],
['type' => 'sellissimo_awaiting_shipment', 'width' => 50],
['type' => 'sellissimo_sales', 'width' => 100],
```

Low stock shows up to eight SKUs with the lowest available quantities. The shipping queue shows the oldest eight physical orders awaiting dispatch. Lifetime sales includes captured orders, including subsequently refunded orders. Settled refunds appear separately in the original currency.

## Back up and upgrade

Back up the configured commerce database, Statamic collections and blueprints, private download files, configuration, and the site's application key together. For SQLite, take a consistent snapshot with SQLite's backup command during operation or stop writers before copying the database. Test restoration with an order receipt and an authorized download. Keep purchased files at their recorded paths.

Upgrade the package versions with Composer, then run `php please sellissimo:install` and rebuild any deployment caches. Flush old Statamic and reverse proxy page caches when enabling the shop. The package excludes its storefront and API from Statamic's default static cache and sends private, no-store responses. Wrap buyer-specific markup on custom pages in a Statamic nocache region or exclude the page. Configure external caches to honor private responses.

See [storefront and API integration](docs/storefront.md) and [extension development](docs/extensions.md).

### Upgrading to 1.1.0

1. Back up the product image container with the rest of the catalog. Update Core and run `php please sellissimo:install`. The migration adds product type and upgrade group to the catalog index and marks listings for rebuild. Existing featured selections and Markdown descriptions continue to work. Saving a description through the new editor writes Bard nodes.
2. Compare your published views in `resources/views/vendor/sellissimo` with the new catalog, product, cart and checkout views. Custom product pages should render the detail response's `description_html`. Custom CSV pipelines can keep the `description` column for Markdown and read `description_bard` for edited products. [Catalog file formats](docs/catalog-and-order-workspace.md#formatted-descriptions)
3. Choose recommendation modes for each site. With the default configuration they start off. If you set any of the four recommendation flags in `sellissimo.default_features` before migrating, the migration adds those flags to existing saved site selections. Subsequent Control Panel changes take precedence. [Store settings](docs/store-settings.md#configure-product-recommendations)
4. Before enabling mandatory policies, publish the relevant entries on each site and update every custom checkout to display the documents and submit their reviewed version. Existing API clients can continue checking out with policy controls disabled. [Checkout request fields](docs/checkout-extensions.md#checkout-policies-and-newsletter-opt-in)

### Catalog and checkout checks

Run `php please sellissimo:catalog-check` after importing entries. The command lists both entries for each conflicting SKU and exits with a failure status if it finds any. Publishing checks simple and variant SKUs within a site. Conflicting imported SKUs disappear from sale until corrected. Translations on different sites can share the same stock.

Buyers see physical stock per variant. The cart identifies shortages across personalized lines and stays editable. For digital orders, the billing country and the configured tax zones determine whether the buyer must supply a region or postal code before seeing the final total.

### Staff, parcels and returns

Grant staff Control Panel access and the Sellissimo permissions for their work under Statamic roles. **Manage Sellissimo store** grants every store action. Fulfillment staff can open orders, print slips, send tracking, and receive returns. Order support covers notes, receipt resends, and download access. Refunds, pay-on-delivery collection, exports, draft orders, inventory, discounts, taxes and sales reports each have their own permission. Order actions include access to view orders.

Open a physical order and choose the quantity of each line in **Ship a parcel**. Enter zero for items going later. Each parcel has its own tracking number, printable slip and optional email. A partial order stays in **Awaiting shipment**. In **Receive a return**, choose the item, quantity received and number fit to sell. Record refunds separately. Upgrading carries existing shipments and pending emails forward.

To sell from several existing collections, set `'collections' => ['books', 'merchandise']` in the published configuration. The single `collection` setting continues to work. Run `php please sellissimo:install --blueprint=books.book --blueprint=merchandise.item` to add the commerce fieldset to those existing blueprints. The installer preserves existing sections and skips a fieldset already attached. You can also import `sellissimo::product` in the blueprint editor. The price inputs follow each currency's decimal precision. Entry files and APIs retain integer minor-unit prices.

### Product upgrades and order relationships

In a product’s sales fields, select **Required previous purchase** to restrict it to an account with a paid purchase of the selected product on that site. Buyers can claim an earlier guest order through their account. A fully refunded order or fully refunded prerequisite line no longer qualifies.

Add the **Sellissimo orders** relationship field to any Statamic blueprint, for example a warranty claim. Staff with order access can search by number, name or email, select an order and open its control-panel page. The field stores order IDs. Customer details stay inside the order workspace.

### Merchant guides

- [Set up a store, change currency and check delivery](docs/store-settings.md)
- [Catalog imports, order queues and pick lists](docs/catalog-and-order-workspace.md)
- [Gift vouchers, split payments and refunds](docs/gift-vouchers.md)
- [Store features, staff alerts and dashboard activity](docs/store-operations.md)
