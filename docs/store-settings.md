# Set up your store

Open **Store → Settings** in Statamic. The overview shows the selected site's currency, storefront, payment methods and delivery coverage. Follow a setup task to the page where you can resolve it. On a multisite installation, choose the site before editing. A field marked **All sites** affects the entire installation.

## Place your first order

1. Open **Store details and currency**. Choose the selling currency by name or code. Check the example price. Enter your trading name, contact email and business address and check the receipt preview.
2. Open **Catalog and inventory**. Choose the product collection and follow any blueprint or pricing messages. In Statamic's entry editor, publish a product with a unique SKU and a price in the selling currency. Give a physical product available stock at an inventory location.
3. In **Checkout and customers**, enable the bundled storefront if you use it. Choose the countries you sell to and your guest or account policy. A custom storefront can use the commerce API while the bundled storefront is disabled.
4. For a physical product, open **Shipping and pickup**. With Flat Rate installed, open **Delivery zones, rates and quote preview**. Choose a destination, enter a rate in the selling currency and preview the customer's address. Enable collection on delivery for this service if you offer it. Downloads and virtual services skip delivery.
5. Open **Payments**. To accept cash on delivery, enable **Pay on delivery** for the site. For card payments, connect a test Stripe or Mollie account and select it for the site. Use the provider's test checkout. The connection check, latest callback and completed checkout are separate observations. Credentials can be replaced by authorized staff.
6. Open the storefront as a buyer, add the product, review tax and delivery, and place the order. Find the same order and total under **Store → Orders**. For a downloadable product, verify that a paid buyer can open the private download from their receipt.

## Change currency on a trading store

Use **Prepare prices** for the proposed currency. The product and variant editors then accept prices for it alongside existing prices. The preview identifies missing prices and delivery rates, affected promotions, open carts and drafts, and any providers that need a test order. Enter the intended prices for that market. When ready, review the impact and activate the currency. Restoring the inherited currency follows the same review.

A returning buyer can review the new prices and rebuild an open cart. Staff can reprice an open draft and enter fresh amounts for custom work and delivery. Issued receipts, outstanding payment sessions, refunds and voucher balances use their original currency. A settings change never supplies an exchange rate.

## Where each setting lives

| Area | Scope and effect |
| --- | --- |
| Store details and currency | Site. Saved contact details appear at checkout and on new receipts. The receipt retains the identity recorded on purchase. Locale comes from Statamic's site and time zone from Laravel. |
| Catalog and inventory | Product collections and the low-stock threshold are shared. Blueprint warnings, price coverage and custom product links use the selected site. Edit stock quantities and locations in the inventory workspace. |
| Checkout and customers | Site. Configure the bundled storefront, billing and delivery countries and their defaults, checkout steps, account and guest access, sign-in and registration links, and published policy pages. Choose which policies require acceptance, whether to offer newsletters and how to label the payment action. The feature editor controls product recommendations, wishlists, vouchers and stock subscriptions. |
| Payments | Methods, connection selection and Stripe checkout presentation apply to the selected site. Test and live credentials are shared and require permission to manage payment credentials across all sites. Hosting-managed credentials appear read-only. Disabling a method affects new payments. |
| Shipping and pickup | Choose installed delivery and carrier integrations. Flat Rate zones and rates apply across all sites. An extension can supply pickup settings or another delivery editor. |
| Taxes and pricing | Tax classes, inclusive pricing and zones are shared. The physical tax address and shipping tax class are site preferences. Use the sample calculation to see the tax and total for one item at an address. Open Discounts to manage promotions. |
| Orders, returns and downloads | The starting order number is shared and can be set before issuing an order. Return windows and download defaults use the selected site. An explicit product or variant download limit takes precedence. |
| Emails and notifications | Site sender, reply-to and staff alert recipients. Test email goes to the signed-in staff member. Customer notifications include order receipts, shipment details and recovery reminders. The shared recovery schedule and retention settings affect every site. |
| Integrations and system status | Installed providers, the commerce database, mounted route, private file storage, mail transport and last observed scheduler run. Open the outgoing webhooks editor to manage shared endpoints. Run the private storage check to verify private write, read and removal of a temporary file. |

## Configure product recommendations

In **Store → Settings → Checkout and customers**, open **Wishlists, vouchers and back-in-stock alerts**. Choose a mode separately for **Complementary products** (cross-sells) and **Product upgrades** (upsells) on the selected site.

| Mode | What the buyer sees |
| --- | --- |
| Off | The selected recommendation block is hidden. |
| Hand-picked only | Eligible products from the source product's **Complementary products** or **Product upgrades** field, in the order the editor chose. |
| Hand-picked and automatic | Hand-picked items first, then eligible automatic matches to fill the block. |

For cross-sells, automatic matching prioritizes products bought together in paid orders. To supply matches for a new store, add **Complementary category pairs** in **Catalog and inventory**. A pair such as coffee → filters recommends filters to a coffee buyer. Add the reverse pair if filters should also recommend coffee. Each row uses existing category slugs.

For upsells, enter a shared **Alternative product group** on interchangeable products, or use their shared **Product type**. Automatic matching selects higher-priced alternatives and gives greater weight to the same group. The product page displays both kinds. The cart offers cross-sells for its contents. Products already in the cart and duplicate suggestions are excluded. The separate **Related products** field keeps its existing purpose.

For a configuration-managed default, enable the base flag and its matching automatic flag together, for example `'default_features' => ['cross_sell', 'auto_cross_sell', 'upsell']`. This enables automatic cross-sells and hand-picked upgrades. Add `auto_upsell` to enable automatic upgrades. Preserve any other features you want in the defaults. Per-site saved selections take precedence. Define inherited pairings under `cross_sell_category_pairs`, keyed by site, as a list of rows with `source` and `target` slugs.

## Existing stores and hosting

After installing or updating the matching packages, run `php please sellissimo:install` and publish the updated Control Panel assets using `php artisan statamic:install`. Existing configuration provides the defaults. Save only the settings you want to change. **Restore defaults** removes a saved override. If someone else saved the same section first, reload and review their changes before saving yours.

The commerce database connection, filesystem credentials, encryption key, mail transport, Laravel scheduler and storefront URL prefix belong to the installation's hosting configuration. Keep the database and Laravel encryption key backed up. The system screen reports observed checks. A mail transport accepting a message confirms dispatch to that transport. A buyer's inbox and an online payment callback are verified separately.

Developers can lock ordinary fields in `config/sellissimo.php`. For example, `'locked_settings' => ['currency', '@store' => ['product_collections']]` locks currency everywhere and product collections across the store. A site-scoped entry such as `'fr' => ['guest_checkout']` locks that site's guest policy. Payment credentials use the payment extension's hosting controls. [Extension settings API](extensions.md)

## Configuration reference for developers

The package config remains the source of inherited values. For a new site, choose its currency explicitly. The following references identify the owning editor and the code that reads saved settings. Field definitions contain the validation rules and shared-scope markers. Staff permissions are enforced again on the write routes. [Field definitions](../src/OperationalSettings.php) · [Store details and email fields](../src/StoreIdentity.php) · [Permissions](../src/StaffAccess.php)

| Inherited setting or existing data | Owner and validation |
| --- | --- |
| `collection`, `collections`, `low_stock_threshold` | Catalog and inventory. Selection uses existing collection handles. The shared threshold is a non-negative quantity used by the stock queue and alerts. |
| `currencies` | Store details and currency. One currency per site. Existing product and variant prices retain their currency keys. Staff prepare new prices and review availability before switching. |
| `storefront`, `checkout_countries`, `checkout_steps`, `product_urls`, `account_urls` | Catalog or Checkout and customers, per site. Validates countries and defaults, registered steps, permitted URLs and route conflicts. Policy links refer to entries. Checkout uses the effective settings. |
| `default_features`, `cross_sell_category_pairs` | Storefront features and Catalog and inventory. Recommendation modes are independent per site. Category pairs are directional. |
| `checkout_policy`, `checkout_method_descriptions` | Site checkout policy defaults and storefront method help text. Configure document entry IDs and the four independent checkout switches. [Checkout configuration](checkout-extensions.md#checkout-policies-and-newsletter-opt-in) |
| `shipping`, `carriers`, Flat Rate `zones` | Shipping and pickup. Select installed providers. The Flat Rate extension owns countries, region/postcode rules, pay-on-delivery eligibility and amounts keyed by currency. Carrier bookings keep their provider reference. |
| `payments`, Stripe `presentation`, `secret`, `webhook_secret`, `publishable_key`, Mollie `key` | Payments. Site selection controls new orders. The account editor requires a separate permission and checks provider identity and mode. The extension's `lock_credentials` option gives hosting control of the connection. |
| `tax_settings`, `taxes`, tax classes/zones, `discounts` and saved promotions | Taxes and pricing. Site address and shipping-class preferences use the tax preferences editor. Global tax classes/zones and site or global promotions retain their existing editors and validation. The sample tax calculation and checkout use the same tax rules. |
| `unit_price_providers`, `order_charge_providers`, `discount_rules` | Taxes and pricing. Installed extensions supply the available handles. Checkout runs price and fee rules in the selected order. Existing campaigns can refer to an installed discount rule. |
| `first_order_number`, `access_days`, return policy, product download limits | Orders, returns and downloads. The storewide sequence locks when the first order number is issued. Set access and expiry defaults for new orders, and edit the site's return policy in Returns. |
| Sender, reply-to, site feature flags, staff alert recipients and `recovery` | Emails and notifications and Checkout and customers. The mail sender, receipt and alert jobs use the site's preferences. Reminders respect customer opt-in. Shared retention and inactivity settings apply to the recovery queue. |
| `prefix`, `connection`, `sqlite_path`, `download_disk`, `download_upload_kb` | Deployment configuration. These control application routes, storage location and upload limits. The system page reports the route, database and private-disk checks. Publish the package config for hosting overrides. |
| `activity_widget`, `order_columns_by_role`, `locked_settings` | Installation-level CP preferences. Roles and personal order views control the order queue. Hosting config controls widget registration and deployment locks. |
| Webhook endpoints, subscribed events and delivery attempts | Existing outgoing webhooks editor under Integrations and system status. Endpoints receive events across sites and require storewide authorization. |
