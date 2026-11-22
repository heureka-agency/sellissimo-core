# Storefront and API

The optional storefront lives at the configured prefix and uses regular HTML forms. All checkout prices are server-calculated. For a custom Antlers storefront, use `{{ sellissimo:products }}` for published, priced SKUs on the current site, or pass `site="fr"`. Each item exposes sku, title, currency, price in minor units, and digital. `{{ sellissimo:cart }}` exposes the current cart's id, lines, and totals. The optional site parameter selects a site-specific cart.

```antlers
{{ nocache }}
<form method="post" action="{{ route:statamic.sellissimo.form.create }}">
    {{ csrf_field }}
    <button>Start a cart</button>
</form>
{{ sellissimo:cart }}
<form method="post" action="{{ route:statamic.sellissimo.form.item :cart="id" }}">
    {{ csrf_field }}
    <label>SKU <input name="sku" required></label>
    <label>Quantity <input name="quantity" type="number" value="1" min="0"></label>
    <button>Update item</button>
</form>
{{ /sellissimo:cart }}
{{ /nocache }}
```

The remaining form route names are `statamic.sellissimo.form.quote` and `statamic.sellissimo.form.checkout`. Both take the cart ID. Submit the same address, discount, delivery, email, and gateway fields as the JSON endpoints below. The quote stores the selection in the buyer's session. Checkout takes the final submitted values. The package's shop template demonstrates the complete form flow. Private forms also work on custom templates with the optional storefront turned off.

## Theme the bundled shop

The catalog, product pages, cart, checkout and signed buyer pages share a Blade layout. Each catalog card opens a product page. Buyers choose quantity, variants and personalization there, then add the item to their cart. **Continue shopping** returns them to their catalog results. A signed-in buyer can select a saved address and choose **Use this address** during checkout, including with JavaScript turned off. The cart is available at `GET /{prefix}/cart` through the named route `sellissimo.cart.page`. Pass the active site and catalog filters to preserve the shopper’s route back to the products.

For a brand adjustment, add a style after the bundled defaults using the layout’s `head` stack, or override `resources/views/vendor/sellissimo/storefront-styles.blade.php`. The stylesheet is scoped to `.ss-store`. Set `--ss-font`, `--ss-accent`, `--ss-accent-text`, `--ss-canvas`, `--ss-surface`, `--ss-border`, and `--ss-focus` to match your site. Choose an accent and text color that remain legible together. Set the trading name in **Store → Settings → Store details and currency**. Custom Antlers storefronts can continue using the existing form tags and JSON API.

### Product image gallery

The bundled product page displays the active variant's image first, followed by the product gallery in the editor's order. On a device with a mouse, hovering over a photo magnifies the area under the pointer. Clicking or tapping any photo opens the full-screen viewer. Select **Zoom** to enlarge the picture, then drag or scroll to inspect it. Touch users can pan with one finger. Select **Zoom** again to fit the picture to the screen.

Use the previous and next buttons to move between photos. The left and right arrow keys also switch photos while the image is fitted to the screen. Close the viewer with its close button or Escape to return focus to the original photo. Without JavaScript the photos link directly to their image files.

The lightbox and hover zoom are enabled independently by default. Set either environment variable to `false` to turn off that feature, then rebuild your configuration cache if your deployment uses one.

| Environment variable | Key in `config/sellissimo.php` |
| --- | --- |
| `SELLISSIMO_PRODUCT_LIGHTBOX` | `product_gallery.lightbox` |
| `SELLISSIMO_PRODUCT_HOVER_ZOOM` | `product_gallery.hover_zoom` |

If your theme overrides the product view, replace its gallery loop with `@include('sellissimo::product-gallery', ['images' => $product['images']])` inside the bundled product template. Custom Blade layouts should render `@stack('head')` and keep the gallery inside `.ss-store`.

### Catalog filters and automatic cart updates

The bundled filter form updates results when a buyer changes a selection or pauses while typing. It updates the query string so buyers can bookmark a search or use Back and Forward. The advanced filters remain in place while results change. Keep the ordinary GET form and pagination links when overriding the view.

For an HTML refresh, GET `/{prefix}` (route `sellissimo.shop`) with the catalog query parameters and `X-Sellissimo-Fragment: catalog`. The response contains the `[data-catalog-results]` element with active filters, count, results and pagination. The bundled `catalog-filters`, `catalog-response` and `catalog-interactions` views share this contract. The JSON catalog endpoint below is available for clients rendering their own list.

On the product page, the quantity input and Add to cart button sit side by side on desktop and mobile. In the cart, changing a line quantity automatically refreshes totals and the header count. The script serializes changes before proceeding to checkout. A buyer using ordinary forms can submit the quantity or remove a line. For the same response in a custom theme, POST the session cart's SKU, absolute `quantity` (0–999) and CSRF token to `/{prefix}/cart/{cart}/items` (route `sellissimo.item`). Preserve the `line_key` when editing a personalized line. With `Accept: application/json`, the response contains `html` for the cart content and `cart_label` for the header. The v1 API's separate PUT endpoint returns HTTP 204.

### Trust information and site-wide search

Supply a `resources/views/vendor/sellissimo/trust.blade.php` partial to display merchant information on the bundled product, cart and checkout pages. The partial receives `$placement` as `product`, `cart` or `checkout`. To use it on the catalog, include it from your shop override with `@includeIf('sellissimo::trust', ['placement' => 'catalog'])`. Use delivery costs from the current quote, actual contact details or links to the site's published policies.

A site's global search can combine CMS entries with a grouped product query. Resolve `Sellissimo\Core\CatalogBrowse` from the container and call its `page` method with the active site, validated catalog filters including `q`, and `groupProducts: true`. The first return value is the products paginator. Resolve each card's destination with `Sellissimo\Core\ProductMetadata::storefrontUrl($site, $product)`. Include a result only when the destination is available to buyers on that site. The site owns the combined results page and search navigation.

## JSON API v1

Base URL: `/api/sellissimo/v1`. Send `Accept: application/json`. Initialize a web session with `GET /session`, retain its cookies, and send the returned token as `X-CSRF-TOKEN` on writes. Requests that read or change a guest cart also need `Authorization: Bearer <cart token>`. Keep the token private to that buyer. A headless web server can maintain a separate cookie jar per buyer. A credentialed browser frontend must use the site's Laravel session and CORS configuration. Account history uses the site's existing Statamic login.

| Method and path | Input and result |
| --- | --- |
| GET /catalog?site=default | Published variants with current prices, ordered gallery images, specifications, categories and availability. Filter by `q` and category slug with `category`. Featured items appear first. |
| GET /catalog/{sku}?site=default | One published variant with product details including `product.description_html`, public related products and links between variants via their SKUs. |
| POST /carts | Optional site handle. Returns the cart ID and guest token, or a null token for an authenticated account cart. |
| PUT /carts/{id}/items | SKU and absolute quantity from 0 to 999. Zero removes a line. |
| POST /carts/{id}/quote | Optional address, discount and shipping. Returns current lines, subtotal, discount, tax, quoted options, shipping, total, enabled payment handles and the site's `checkout_policy`. |
| POST /carts/{id}/checkout | Email, billing country, gateway, optional discount. When configured, include the required `consents` and `policy_version`. Physical orders also require a delivery address and a valid shipping option. Returns order, status, checkout URL and private receipt URL. Repeating checkout for the same cart reuses the order. |
| GET /orders/{id} | Signed receipt URL or owning user's session. Returns status, tracking, receipt and eligible download links. |
| GET /orders/{id}/downloads/{sku} | Signed URL or owning user's session. Streams the private file after checking current entitlement. |
| POST /account/logout | Sign out of the current Statamic session and return to the selected site. Submit with the session CSRF token. |
| GET /account/orders?page=1 | Paginated signed-in buyer history, including currently accessible downloads. |

Example physical checkout payload:

```json
{
  "email": "buyer@example.com",
  "address": {"name":"Sam","country":"US","street":"10 Elm St","city":"Boston","postal_code":"02108"},
  "shipping_address": {"name":"Casey","country":"US","street":"24 Park St","street2":"Suite 7","city":"Boston","postal_code":"02108"},
  "shipping": "flat_rate.standard",
  "discount": "WELCOME",
  "gateway": "stripe"
}
```

Use `address` for billing and optionally `shipping_address` for a separate delivery recipient. Both accept `name`, `company`, `street`, `street2`, `city`, `region`, `postal_code`, `country` and `phone`. Billing determines tax. Physical orders use the delivery address for shipping quotes and packing slips, falling back to billing when it is omitted. Supply uppercase two-letter country codes. A digital-only order only needs the billing country for tax. A zero-total order completes without a gateway. Redirect the buyer to the returned URL. Use the receipt endpoint to display final status. A browser redirect alone does not settle an order.

If the provider has yet to confirm session creation, the checkout API returns HTTP 202 with `payment_session_pending: true`, the original order ID and a signed `receipt_url`. Show that receipt. The scheduler retries the existing payment, and the receipt shows a payment link when it becomes available. A definitive refusal of the first creation request returns HTTP 422 with `payment_rejected: true`, a buyer-safe message and the signed receipt. Sellissimo releases the stock and voucher reserved for that attempt. An existing or overlapping attempt stays pending until its outcome can be verified.

In **Store → Settings → Checkout and customers → Wishlists, vouchers and back-in-stock alerts**, administrators can see which settings are missing for enabled payment extensions. A paid order with no usable gateway cannot be placed. Set up the scheduler so existing pending orders and amendments continue to reconcile.

Unowned resources return 404, invalid selections or input return 422, and stock conflicts or attempts to edit a checked-out cart return 409. Recover a stock conflict by changing quantities and requesting another quote. Create a new cart after checkout or after a site currency changes.

### Personalized lines and customer carts

Send an optional `options` object alongside `sku` and `quantity`. Its keys are the handles in the catalog product’s `option_fields`. Unknown keys, missing required values, non-text values and excess lengths fail validation. A quote returns a stable `key` and the display labels and values for each line. Pass `line_key` and the SKU when updating an existing line’s quantity or removing it with quantity zero.

Guest carts use the existing bearer token. A signed-in request to create a cart returns the current account cart with a null token. Send the same authenticated session and CSRF token for subsequent requests. A previous guest token loses access once a cart belongs to an account. The account orders page includes the email-confirmed guest purchase workflow.

### Promotions

When quoting, omit `discount` or send an empty string to apply an eligible automatic offer. An explicit coupon takes precedence. Supply `email` on quote requests to check a guest's per-customer allowance. A quote without guest identification is provisional. Checkout always validates the final buyer and available uses.

The quote and receipt include `promotion` with the applied identifier, display label, application method (`automatic`), merchandise discount in minor units, and free-shipping flag. A cart with no offer returns `null`. The existing `code` field contains only the customer-entered coupon. Orders created before this release may omit `promotion`.

The cart quote also returns `quote_token`. Send it on checkout to require the reviewed price and offer. A changed quote returns HTTP 409 so the buyer can review it again. The built-in storefront sends it automatically. An exhausted or ineligible entered code returns HTTP 422, with no order or stock reservation.

Catalog items include `available` (integer units for physical variants, null for downloads). Quotes expose `stock_shortages` keyed by SKU with quantities available, `billing_fields` and `missing_billing_fields`. Display an address prompt while any billing fields are missing, and offer cart edits for shortages before enabling checkout. A cart or quote does not reserve units.

Receipts include `parcels`, each with a tracking number and a map of purchased line keys to shipped quantities. A personalized line uses its original order line key. The existing `tracking` value remains available for storefronts displaying a single tracking number.


### Product pages and catalog fields

With the optional storefront enabled, a product's page is at `/shop/products/{sku}` under the configured prefix. Its add-to-cart form increments the selected variant and personalization. The API cart item update uses an absolute quantity.

The product detail's `description_html` renders legacy Markdown or Bard content, including inline images with public asset URLs and alternative text. Use it for a formatted description in a custom storefront. The existing `description` field supplies the original string for Markdown entries and plain text for Bard entries.

Catalog prices and `compare_at_price` use integer minor units. During an active sale, `price` is the sale price and `compare_at_price` is the regular price. `unit_price` contains the price per unit, unit and pack quantity when configured. Each image supplies a public URL and alternative text. Variants can override the cover image. `digital` marks items that require no shipping. `downloadable` distinguishes purchased files from services. The catalog, Antlers products tag and buyer receipts omit internal costs and private file paths.


### Address books, returns and shipping instructions

The signed-in buyer can open `GET /account/addresses?site=default` from **My orders**. Request HTML for the forms or JSON for the address book. The JSON contains `entries`, default entry IDs in `billing` and `shipping`, and a `revision`. POST to the same URL with the current revision, `action=save`, a label, address and optional default flags. Include the entry ID to edit it. To remove it, send `action=delete`, the ID and revision. The built-in shop uses the account defaults when starting a quote. Changes apply to future purchases.

A shipped order's receipt includes a `returns_url`. Use that URL for the return form or request JSON for line choices, permitted quantities, replacements and the buyer’s request history. A buyer can use their owning session or the signed URL provided on the receipt. POST `request_id` (the form's UUID), `line_key`, `quantity`, `reason` and optionally a replacement SKU. Repeating the same request ID with identical details reuses the request. The response includes the store's decision, message and any replacement tracking number.

`shipping_instructions` is an optional string of up to 500 characters for physical deliveries. Staff can set it on a draft and the buyer can review it on the payment link. The order screen, receipt and packing slip show the saved instructions.


Checkout quotes include `delivery_ready` and `missing_shipping_fields`. For physical goods, show delivery pending until the buyer has chosen a service and supplied the complete address. When a supplied destination has no available options, prompt the buyer to check the address or contact the shop. Show the final total and payment action once delivery and tax are ready and stock is available. A confirmed zero-cost option is free delivery. A taxable shipping option's `amount` includes its `tax`, which is also included in the receipt's overall tax figure. Digital orders skip delivery.

## Catalog pagination and custom fields

The JSON catalog accepts `site`, `q`, `category`, `page` and `per_page` (1–100, default 24). Read products from `products` and follow `pagination.next_page_url` to continue. The built-in shop pages through the same search. Stock and prices are read for the current request. Individual product responses include the selected product’s variants, related products and public line-field definitions.

Under **Blueprints → Sellissimo**, edit **Order fields** and **Line item fields**. The built-in shop and draft editor support text, textarea, integer, date, toggle, select, radio and single-file assets fields. A text field can capture a purchase order number. A date field can capture the requested delivery date. Add validation rules in the blueprint. Fields with hidden or read-only visibility are available to staff on drafts and omitted from buyer responses and packing slips.

Send line values as `line_fields` when adding an item, and order values as `order_fields` when quoting or checking out. A successful quote saves order fields to its cart. Submit a file in a multipart request, or POST `type` (`line` or `order`), `handle` and `file` to `/api/sellissimo/v1/carts/{cart}/artwork` using the cart’s credentials and session CSRF token. Supply the returned ID as the field value. Artwork accepts PDFs, JPEG, PNG and WebP up to 10 MB. It is stored privately. Staff can download it from the owning order. Purchased field labels and values are preserved if the blueprint later changes.

To opt into checkout reminders, send `recovery_consent=1` and `email` with a quote. Send `recovery_consent=0` to withdraw consent. Signed recovery links work independently of the optional shop.

Merchants can limit an offer to individual SKUs or whole products, including future variants. A single explicit coupon takes priority. If an applied rule allows combination, other eligible automatic rules that also allow combination follow in priority order. Percentage discounts use the remaining eligible merchandise value. Free delivery can combine with merchandise discounts. Quotes and receipts include all offers in `promotions` with their line allocations in minor units. The original `promotion` key continues to contain the first applied offer.


## Antlers cart forms and order history

Wrap buyer-specific forms, order history and cart values in Statamic’s `nocache` tag on statically cached pages. The forms work whether the optional storefront is enabled or disabled. `add_item` creates or resumes the current site’s cart and increments the line quantity. It defaults to one. `update_item` sets the exact quantity. A quantity of zero removes the line. Pass the line key when updating a personalized item.

```antlers
{{ nocache }}
    {{ sellissimo:add_item sku="MUG-SAND" }}
        <label>Quantity <input type="number" name="quantity" min="1" value="1"></label>
        <button>Add to cart</button>
    {{ /sellissimo:add_item }}

    {{ sellissimo:cart }}
        {{ lines }}
            {{ title }} · {{ total | sellissimo_money }}
            {{ sellissimo:update_item :sku="sku" :line_key="key" }}
                <label>Quantity <input type="number" name="quantity" min="0" value="{{ qty }}"></label>
                <button>Update</button>
            {{ /sellissimo:update_item }}
        {{ /lines }}
    {{ /sellissimo:cart }}

    {{ sellissimo:orders as="purchases" limit="20" }}
        {{ purchases }}
            <a href="{{ receipt_url }}">{{ number }}</a> · {{ total | sellissimo_money }}
        {{ /purchases }}
        {{ if no_results }}<p>No orders to display.</p>{{ /if }}
        {{ if prev_page_url }}<a href="{{ prev_page_url }}">Previous orders</a>{{ /if }}
        {{ if next_page_url }}<a href="{{ next_page_url }}">More orders</a>{{ /if }}
    {{ /sellissimo:orders }}
{{ /nocache }}
```

The form tags generate their action and CSRF field. Use `site="fr"` consistently on the cart, forms and history when selecting a site explicitly. `sellissimo:orders` reads the signed-in buyer’s orders for that site. Its default page size is 25. Signed-out visitors receive an empty result. The money modifier accepts amounts in minor units and uses the row’s currency or the current site’s currency. Use `{{ amount | sellissimo_money:JPY }}` to choose a currency explicitly.

A download link delivers the purchased file or a ZIP when the line contains multiple files. Limited lines in the receipt include `download_limit` and `downloads_remaining`. The last permitted download closes access to that line across signed links and account history. A positive `download_days` on the line sets an access period measured from payment confirmation. Earlier purchases keep their original terms.

The bundled catalog links each product to its details. Buyers select variants, quantity, personalization and artwork on the product page. Adding an item opens the dedicated cart. The header shows the item count and links to the cart, where buyers can edit quantities and choices, remove items, see their subtotal and continue to checkout. Delivery address validation follows the destination country's postal, locality and region requirements. Where a territory uses an area without a required city, its locality can be entered in either city or region. Digital-only orders retain the billing fields required for tax.

### Filtering and checkout

The bundled catalog and the products endpoint accept `in_stock=1`, `price_min` and `price_max` in the site currency’s major units, `sort=price_asc` or `price_desc`, and attributes such as `attributes[Color]=Blue`. All selected attributes must match one SKU. In-stock results include downloadable, virtual and made-to-order products available for purchase. The bundled catalog groups matching SKUs by product and keeps filters in pagination links.

The `sellissimo:cart` tag returns valid lines and their subtotal alongside an `unavailable` list. Each unavailable line includes its key, SKU and last known title so a custom storefront can offer removal.

Signed-in buyers can select named addresses saved for the current site in the billing and shipping sections of checkout. Edits on the checkout form apply to the order. The customer’s site-specific order list shows the original charge, additional charges, refunds and net paid. The receipt shows the current delivery address, including a merchant correction.

### Catalog navigation and cart availability

The bundled shop keeps the buyer’s search, category, price bounds, selected attributes, stock filter, sort and page when opening products, choosing variants, following related products and returning to the cart. Each tab carries its own browsing state in its links.

If stock falls below the quantity in a cart, the buyer sees the available count and can reduce a line to the quantity still available or remove it. When multiple personalized lines share a SKU, each suggested correction accounts for the other lines. The buyer chooses which personalization to keep. Checkout opens when all unavailable lines and stock shortages have been resolved.

Guest buyers can open **Find my receipt** in the catalog or account, or on an expired receipt page. The public form also works independently at `/api/sellissimo/v1/receipts/recover`. Submit the displayed order number, billing email and optional site. The response is the same for every valid submission. A match sends a fresh signed link to the recorded billing email. Each order can receive one recovery message per ten minutes.

Catalog and account order pages use the themeable `sellissimo::pagination` view. Previous and next links retain active filters. Each shipped parcel can include a public HTTPS tracking link in its receipt and shipment email.

### Retrying an expired payment

An expired online order’s receipt offers **Try payment again**. The buyer can review and edit a new basket containing their original quantities, options, personalizations and artwork. Checkout recalculates current prices, discounts, delivery and stock. The previous order keeps its number and payment history. A buyer who already has a basket can return to it during review, and it resumes after checkout.

For a custom storefront, POST to the signed `retry_url` from the receipt using the same browser session and CSRF protection. The response includes the cart ID and guest bearer token, or an account-owned cart ID. Request a fresh quote and send its `quote_token` when checking out. Account orders require the matching signed-in buyer. Repeated requests from the same browser resume the existing retry cart.

### Product search metadata

The bundled product page provides one canonical URL for each SKU and site. Filter and pagination parameters remain available for returning to the catalog. The JSON-LD describes the displayed product, price, currency and availability. Variant pages link sibling variants through a common ProductGroup. Overridden product views can reuse `ProductMetadata::url` and `ProductMetadata::schema`.


The bundled cart has an **Edit choices** link on customizable lines. Existing choices, quantity and private artwork are prefilled. A headless storefront can send changed `options` and `line_fields` with the original `line_key` to the existing cart-item endpoint. Omitted choices are retained. An identical configuration merges into its existing line, subject to the per-line maximum. Uploads must belong to the same cart and blueprint field.

The buyer’s return page exposes incoming tracking and signed access to its return label. A received exchange with a positive balance offers a payment link once the merchant opens settlement. Email updates always link back to that return.

### Postal regions and local payment testing

The storefront, saved addresses and draft editor suggest states and provinces for countries with postal subdivisions. Country changes refresh the choices. The quote accepts a recognized code, English name, native alias or country-prefixed code. It compares these consistently against historical rules such as “California” and “CA”. For countries with no predefined subdivision list, the region stays free text. Tax and flat-rate postcode rules support `*` for zero or more characters and `?` for one character. Postal subdivision data is derived from [CommerceGuys Addressing](https://github.com/commerceguys/addressing/tree/v2.3.3/resources/subdivision).

To try a priced purchase locally, add `simulator` to the `payments` array in `config/sellissimo.php`, then clear any cached configuration. Checkout opens a signed test payment page where you can choose success, decline or expiry. Success gives access to the usual receipt and purchased downloads. Decline and expiry release held stock and follow the buyer retry flow. The simulator also supports test refunds. Its payment controls and gateway are available only in Laravel’s `local` and `testing` environments.

### Customer access and digital checkout

The catalog and checkout link to the customer account. An upgrade requiring a previous purchase links a signed-out buyer back to that product after sign-in. A guest receipt links to the account with its order number filled in. Choose existing account pages in **Store → Settings → Checkout and customers**. On Statamic Pro, you can also require sign-in before placing an order. The headless quote includes `guest_checkout` and any published `policy_links` for the cart’s site. The fallback uses Statamic's login and, where supported, Statamic registration. Custom authentication pages should honor Laravel's intended URL.

An account holder can claim a purchase by its displayed number (including legacy eight-character numbers) or its full order ID. A successful claim requires the signed email confirmation and the account matching the billing email. Send `site` and `order` to the account claims endpoint. The guest receipt recovery form continues to work for buyers without accounts.

A digital or service-only basket asks for billing country and the fields needed for its tax and order charges. Changing the country refreshes those fields. With JavaScript disabled, **Continue** reveals any additional requirements. A mixed basket uses the physical delivery flow.

### Featured and popular products

Use `sellissimo:featured_products` for editorial picks and `sellissimo:popular_products` for products ranked by retained paid units on the current site. Merchants mark featured entries in the product's **Merchandising** tab. The bundled catalog displays the two lists on the first page when there are no active search, sort or filter criteria. Empty lists stay hidden.

```antlers
{{ sellissimo:featured_products limit="4" }}
    <a href="{{ url }}">{{ product_title }}</a>
{{ /sellissimo:featured_products }}

{{ sellissimo:popular_products site="default" days="30" limit="6" as="ranked" }}
    {{ if ranked }}<h2>Best sellers this month</h2>{{ /if }}
    {{ ranked }}<a href="{{ url }}">{{ product_title }}</a>{{ /ranked }}
{{ /sellissimo:popular_products }}
```

Both tags accept `site`, `limit` (1–24, default 4) and the optional `as` handle. An aliased list also exposes `no_results`. Each product appears once, with a currently available SKU, product title, URL, price in minor units, currency and images. The popular list accepts `days` (1–365, default 90). Purchases of multiple variants count toward their product. Received returns subtract units and dispatched replacements add units for the replacement product. Fully credited lines, free downloads and voucher products are excluded. A store without qualifying sales has an empty popular list.

### Building a paginated Antlers shop

`sellissimo:catalog` groups variants into products, supports the bundled shop's search, category, attributes, stock, price range and sorting, and preserves them in pagination links. Set `site`, `per_page` or other filter parameters on the tag to override corresponding URL filters. The original `sellissimo:products` tag still returns individual SKUs.

```antlers
{{ sellissimo:catalog per_page="24" }}
    {{ products }}<p>{{ product_title }} · {{ price | sellissimo_money }}</p>{{ /products }}
    {{ if no_results }}<p>No products match.</p>{{ /if }}
    {{ if prev_page_url }}<a href="{{ prev_page_url }}">Previous</a>{{ /if }}
    {{ if next_page_url }}<a href="{{ next_page_url }}">Next</a>{{ /if }}
{{ /sellissimo:catalog }}
```

Use `sellissimo:quote` to post buyer input, including addresses, email, delivery, discounts and custom fields. It returns to your page with the quote saved. Render `sellissimo:shipping_options` (each row has a handle, label and amount) and `sellissimo:payment_gateways` (handle and label) for the current cart and site. After the buyer has reviewed their total, `sellissimo:checkout` submits the saved quote and selected gateway. It includes CSRF, the reviewed quote token and the policy version. If the site configures policy controls, render their labels, document links and individual checkbox fields inside the form. A changed price requires the buyer to review the new total. Use these forms on pages with the optional storefront enabled or disabled. [Checkout policy fields](checkout-extensions.md#checkout-policies-and-newsletter-opt-in)

```antlers
{{ nocache }}
    {{ sellissimo:quote }}
        <label>Email <input type="email" name="email" required></label>
        <label>Billing country <input name="address[country]" maxlength="2" required></label>
        <button>Update digital order</button>
    {{ /sellissimo:quote }}
    {{ sellissimo:cart }}<p>Order total {{ total | sellissimo_money }}</p>{{ /sellissimo:cart }}
    {{ sellissimo:checkout }}
        <label>Payment <select name="gateway">
            {{ sellissimo:payment_gateways }}<option value="{{ handle }}">{{ label }}</option>{{ /sellissimo:payment_gateways }}
        </select></label>
        <button>Place reviewed order</button>
    {{ /sellissimo:checkout }}
{{ /nocache }}
```

The example covers a digital purchase where country determines the tax. For other destinations and baskets render the quote's required billing and shipping fields. After an address change, quote it before rendering available delivery options. With more than one site, specify the same `site` on the catalog, forms and option tags.

### Wishlists and variant stock alerts

With the feature enabled on the selected site, GET `/api/sellissimo/v1/wishlist?site=default` returns the current browser or account list. POST `action=add` or `action=remove` with the site and exact `sku`. Use an HTML form or send the session and CSRF token with an `Accept: application/json` header. The list returns current product URLs and prices. Retired SKUs remain removable.

POST the buyer’s `email`, `site`, and `sku` to `/api/sellissimo/v1/back-in-stock` for a sold-out tracked variant. The package handles email confirmation, unsubscribe and notification. For a custom product route, set `product_urls` in Sellissimo's configuration, for example `['default' => '/products/{sku}']`. With the optional shop disabled, this supplies links from the wishlist and restock emails. Otherwise Sellissimo uses the Statamic entry URL where available.


## Reviewing a cart after a currency change

The built-in storefront shows the buyer which items are available in the new selling currency, their new unit prices, and any removed quantities. The buyer rebuilds the cart and reviews the checkout total. Delivery, discounts and vouchers can be selected again. An expired payment reopened from its receipt after a currency change uses the original currency and quoted item prices. If the original amount cannot be reproduced, the buyer can start a new order.

On custom storefronts, check the `currency_change` value from `{{ sellissimo:cart }}` before rendering ordinary checkout controls. Show `from`, `to`, `lines`, `removed` and the merchandise `subtotal` from that value. After the buyer accepts the preview, submit a CSRF-protected POST to `rebuild_url` with the preview's `digest`. Fetch a fresh quote and show the total before checkout.

API clients use the existing cart credentials for `GET /api/sellissimo/v1/carts/{cart}/currency`. Confirm the preview with a POST to the same URL containing its `digest`. A successful rebuild returns HTTP 204. If prices or availability change between the preview and confirmation, HTTP 409 asks the buyer to review again. Send the new quote's `quote_token` when placing the order.
