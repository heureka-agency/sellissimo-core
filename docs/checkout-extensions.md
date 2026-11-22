# Checkout extensions

## Countries and defaults

In the site's `config/sellissimo.php`, set each storefront's billing and shipping countries using uppercase ISO two-letter codes. The keys are Statamic site handles. An optional `*` entry supplies shared defaults, and settings for a particular site override them.

```php
'checkout_countries' => [
    '*' => ['shipping' => ['AT', 'HU']],
    'default' => [
        'billing' => ['AT', 'DE', 'HU'],
        'default_billing' => 'HU',
        'default_shipping' => 'HU',
    ],
],
```

An omitted list allows the full country dictionary. An empty list accepts no countries for that purpose. Defaults preselect allowed options on the contact form. The buyer still submits the address. Signed-in buyers can choose a saved address from the countries permitted for that field.

Physical orders check the delivery country on the separate shipping address, or on the billing address when the buyer uses it for both. Digital orders check the billing country. Quotes, order placement and signed draft payments enforce the policy. Shipping zones still determine individual rates and service availability within a permitted country.

An addon with a dynamic country list can implement `Sellissimo\Core\Contracts\CheckoutCountryProvider` and register it in its service provider.

```php
$registry->checkoutCountries('territories', new StoreTerritories);
```

Set `'provider' => 'territories'` in the site's `checkout_countries` entry. Sellissimo calls `countries(string $site, string $purpose): ?array` with `billing` or `shipping`. The result narrows the configured list. Return `null` to accept all configured countries. If the result excludes the configured default, the country selector opens on its placeholder.

## Checkout steps

Register a class implementing `Sellissimo\Core\Contracts\CheckoutStep`, then select it in **Store → Settings → Checkout and customers** for the site. The configuration file can also define the inherited sequence.

```php
$registry->checkoutStep('pickup_window', new PickupWindow);

// config/sellissimo.php
'checkout_steps' => ['contact', 'delivery', 'pickup_window', 'review'],
```

The first step is `contact` and the last is `review`. Keep `delivery` in the list. Sellissimo automatically skips it for digital orders. Custom steps run in the order configured. Place a step that depends on the chosen delivery method after `delivery`.

Each step implements three methods.

- `label(): string` supplies the translated navigation and form title.
- `applies(array $publicQuote, string $site): bool` decides whether to ask for these details. The pickup example checks for `shipping_method === 'pickup.counter'`.
- `fields(array $publicQuote, string $site): array` returns fields keyed by lowercase handles. Supported types are `text`, `date` and `select`. Supply a label, a Boolean `required` flag and, for a select, an associative `options` array keyed by the stored code. Additional Laravel rules may be supplied in a `rules` array. The quote passed to these methods excludes step answers and private file paths.

```php
return [
    'window' => [
        'type' => 'select',
        'label' => __('Collection time'),
        'required' => true,
        'options' => ['morning' => __('Morning'), 'afternoon' => __('Afternoon')],
    ],
];
```

Changing an earlier step clears later answers on the included storefront. Buyers review their selections before confirming. Only declared fields on applicable steps reach the saved receipt. Sellissimo validates required fields and option codes on the server, and rechecks current rules when placing an order. The buyer's receipt and the merchant's order details use the labels and values saved with that order. A signed draft payment link collects these details before payment.

For a headless storefront, the cart quote response includes `checkout_step_definitions` in display order. If a country policy is configured, `checkout_countries` includes options and defaults for both address types. Send answers such as `{"checkout_steps":{"pickup_window":{"window":"morning"}}}` with the quote and checkout requests. Carry the final quote's `quote_token` into checkout so a changed price, shipping selection or answer requires a fresh review.

The [local pickup example](../examples/local-pickup/src/ServiceProvider.php) registers the shipping method and its conditional time-window step. Enable `pickup` in `shipping` and include `pickup_window` in `checkout_steps` to try them together.

## Checkout policies and newsletter opt-in

Open **Store → Settings → Checkout and customers** for the active site. Publish the relevant Statamic entries before selecting them as the terms or privacy page. The following keys can also supply inherited defaults under `sellissimo.checkout_policy.<site>`.

| Key | Effect |
| --- | --- |
| `terms_page` + `require_terms` | Select the terms entry by ID and require a distinct acceptance. |
| `privacy_page` + `require_privacy` | Select the privacy notice entry by ID and require acknowledgement. |
| `offer_newsletter` | Offer an optional, initially unchecked subscription. The buyer can complete checkout with it unchecked. Cart recovery has a separate consent field. |
| `payment_obligation` | Use explicit payment-obligation wording on the final button for a positive order total. A zero-total order retains its free-order label. |
| `payment_notice` | Optional text shown near the final button for a positive order total. |

The four switches default to false. A selected entry can also serve as an informational link with its required switch off. A missing or unpublished required document prevents checkout on that site.

**Custom checkouts.** The JSON quote and `sellissimo:cart` expose `checkout_policy`. Read `fields`, `available`, `version`, `payment_obligation` and `payment_notice`. For each enabled field, display its localized `label` and `required` state. Required fields include a `document` with `id`, `url`, `title` and a content version. Show buyers the linked document before asking them to confirm.

Send the current policy version when submitting a mandatory choice or an affirmative newsletter opt-in. For example, add these keys to the normal JSON checkout payload, substituting the values from the reviewed quote.

```json
{
  "consents": {"terms": true, "privacy": true, "newsletter": false},
  "policy_version": "<checkout_policy.version>",
  "quote_token": "<quote_token>"
}
```

Ordinary forms submit `consents[terms]`, `consents[privacy]` and `consents[newsletter]` as appropriate for the site. The Antlers `sellissimo:checkout` form tag supplies the hidden version and quote token. Custom forms still need to display the policy fields. If checkout returns HTTP 422 for a missing consent or changed policy, show the field error, refresh the quote and ask for the buyer's current choices. The server also validates policies on signed draft purchase links.

**Newsletter integration.** Each placed order's stored receipt includes `checkout_consents` for the controls offered. Each entry records `accepted`, `recorded_at`, the localized label and the policy version. For required documents the snapshot also stores the entry ID, URL, title, document version and content as purchased. An unchecked newsletter is recorded as false. Historical orders keep their original receipts.

To connect a mailing provider, listen for the after-commit Laravel event `sellissimo.order.placed`. Its single array argument holds `order_id`, `gateway` and `payment_status`. Use the order ID to read the saved receipt. Queue a subscription only when `checkout_consents.newsletter.accepted` is true, using the order ID to deduplicate retries. The provider connection, delivery and unsubscribe handling belong to the integration.

## Coupons, gift vouchers and method cards

The bundled checkout keeps code entry in the order summary across steps. For a custom version of this session-based form, POST to `/{prefix}/cart/{cart}/checkout-codes/{step}` (route `sellissimo.checkout.codes`). Use the current buyer's cart, an enabled checkout step and the session CSRF token.

| Action | Fields |
| --- | --- |
| Apply | `code_action=apply`, `coupon_code` and optionally `voucher_code` (up to 80 characters each). Send the current coupon when applying only a voucher to retain both. A blank coupon clears the discount. A blank voucher leaves the current voucher in place. |
| Remove a coupon | `code_action=remove_discount` |
| Remove a voucher | `code_action=remove_voucher` |

With `Accept: application/json`, success returns `{"url":"…"}` for the current step. GET that URL to refresh the priced summary, eligible delivery and payment methods, and any required extension fields. A rejected code returns HTTP 422 with field errors. A standard form post redirects to the step. Headless clients use the v1 cart quote and checkout endpoints with their existing `discount` and `voucher` fields and review the updated quote token. [Cart API](storefront.md#json-api-v1)

The checkout renders eligible shipping options and payment gateways as labeled radio cards. Shipping cards display the quoted amount. To customize the supporting text, set `sellissimo.checkout_method_descriptions.<site>` with `shipping` and `gateway` maps keyed by the exact option handle returned by the quote. For example, use `'gateway' => ['pay_on_delivery' => 'Pay when collecting your order.']` for a store that offers collection.

## Cart and customer unit prices

A `Sellissimo\Core\Contracts\UnitPriceProvider` can set a catalog item's base unit price before discount allocation, tax calculations and shipping quotes. Register the provider in the addon's service provider and enable its handle in the store config.

```php
$registry->unitPrice('volume', new class implements \Sellissimo\Core\Contracts\UnitPriceProvider {
    public function unitPrice(int $amount, array $product, array $line, array $cart): ?int
    {
        if ($product['sku'] === 'PAPER' && $cart['currency'] === 'USD' && $line['qty'] >= 10) {
            return 125;
        }
        return null;
    }
});

// config/sellissimo.php
'unit_price_providers' => ['volume'],
```

The cart context contains the verified customer ID (or `null` for guests), site, currency and stored items keyed by cart line. Each item has a SKU, quantity (`qty`) and selected options. Implement account pricing using the customer ID. The buyer-entered contact email is independent of account identity.

Providers run in configuration order. Each receives the previous unit amount, starting with the current catalog offer for that currency. Return an integer from 0 to 99,999,999 in minor units, or `null` to keep that amount. Staff-entered draft prices take precedence, including custom service lines. Keep the method free of side effects as cart display, quotes, stock-checked placement and confirmations may calculate the same price several times.

Use **Store → Settings → Taxes and pricing → Unit price rules in application order** to enable installed pricing extensions. Public catalog listings, sorting and price filters use catalog prices. Render personalized prices from the private cart quote or the `sellissimo:cart` tag. The final order stores the unit amount used for checkout.

## Named order charges

To generate a handling fee addon with a working example and test, run:

```sh
php artisan sellissimo:make-extension charge "Handling"
```

Register an implementation of `Sellissimo\Core\Contracts\OrderChargeProvider` during the addon's boot method and enable it in **Store → Settings → Taxes and pricing → Order fee rules in display order**. The host can supply inherited selections using `order_charge_providers`.

```php
$registry->orderCharge('handling', new HandlingOrderCharge);

// config/sellissimo.php
'order_charge_providers' => ['handling'],
```

Each provider receives the same merchandise and delivery quote after promotions, the site handle, and the verified customer ID (or null for a guest). It can inspect the currency, selected delivery method, billing address, quantities, and public order and line fields. Return null to skip the charge, or return a buyer-facing label and an integer amount in minor units. Amounts between 1 and 99,999,999 appear on the quote. Zero omits the charge. Providers may be called repeatedly and should be free of side effects.

An optional `tax_class` applies the billing address rate for that class. Its inclusive setting determines whether tax is already included in the provider's amount. Omitting the class makes the charge tax-free. Discounts apply to merchandise. The charge is calculated once per order.

For headless checkout, read `charges[handle]` for the saved label, pre-tax `subtotal`, `tax_class`, `tax` and tax-inclusive `total` in minor units. The quote's overall tax and total already include all charges. Pass its `quote_token` when placing the order to detect a changed fee. Completed receipts keep the captured name and amounts. Paid-order amendment previews recalculate charges and collect or credit the difference after confirmation.

Staff can refund all or part of a fee using its order detail refund target. The key `@charge:handling`, for example, reserves from the handling fee's tax-inclusive balance. Whole-order refunds include the remaining fee. Sales reports show pre-tax net order charges separately from merchandise and shipping and account for settled refunds.
