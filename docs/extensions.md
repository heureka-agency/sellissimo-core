# Extend Sellissimo

Depend on `sellissimo/core:^1.0` and declare a service provider in Composer's `extra.laravel.providers`. Laravel's container holds a shared `Sellissimo\Core\Registry`. Register providers in boot and enable the chosen handles in the site's Sellissimo configuration. Use lowercase letters, numbers, and underscores, with a leading letter. Duplicate handles raise an error.

## Create a local extension

You may adapt the bundled local-pickup example and generator templates and distribute your own plugin free of charge or for a fee. Declare Sellissimo as a dependency so merchants can obtain the official packages separately for their sites. [License agreement](../LICENSE)

From a Statamic site, run one of the following commands. Each creates a Composer package under `addons/sellissimo` with a discoverable service provider, an implementation, and a starter PHPUnit test.

```sh
php artisan sellissimo:make-extension shipping "Local Courier"
php artisan sellissimo:make-extension payment "Acme Pay"
php artisan sellissimo:make-extension discount "Member Rate"
php artisan sellissimo:make-extension charge "Handling"
```

For the shipping example, install the generated package into the same site with:

```sh
composer config repositories.local-courier path addons/sellissimo/local-courier-shipping
composer require store-extensions/local-courier-shipping:@dev
```

Add `local_courier` to `shipping` in the Sellissimo configuration when the rates are ready. Payment and discount extensions use `payments` and `discount_rules`. Run `vendor/bin/phpunit addons/sellissimo/local-courier-shipping/tests` from a host with PHPUnit installed. The sample shipping method offers free pickup, the discount returns zero, and the unconfigured gateway refuses to start a payment. Replace these defaults with the provider implementation and extend its tests.

Use `CarrierLabels::updateTracking($provider, $id, $description, $occurredAt, delivered: true)` to record a delivered parcel when a carrier uses its own status wording.

## Shipping

Implement `Sellissimo\Core\Contracts\ShippingProvider` and call `$registry->shipping('pickup', $provider)`. A quote receives physical receipt lines, a destination address, and currency. Return a map such as `['counter' => ['label' => 'Collect in store', 'amount' => 0]]`, or an empty array for an unsupported destination or currency. Sellissimo supplies the checkout selection key `pickup.counter`. Providers must handle repeated quotes without booking a shipment. Each physical line can include a `shipping_details` map with `weight_kg`, `length_cm`, `width_cm`, `height_cm`, `country_of_origin` and `hs_code`. Measurements are for one packaged unit. Use the line quantity when calculating the parcel. A variant measurement overrides the product default. Treat missing measurements as unknown. The destination is the separate shipping address when supplied and otherwise the billing address.

A shipping extension can also implement `Sellissimo\Core\Contracts\ReportsShippingCurrencyCoverage`. Its `currencyCoverage(string $site, string $currency): array` lists configured services for the site's selling destinations. Each row contains `label`, a boolean `priced`, a boolean `free` and a boolean `pay_on_delivery`. Store settings uses these rows when reviewing a proposed currency. Other shipping providers can be checked through the sample order for a specific destination.

The [local pickup package](../examples/local-pickup/composer.json) is a complete independently discoverable example. Copy that directory to a development site's addons folder, add a Composer path repository for it, require `example/sellissimo-local-pickup:@dev`, and enable **Pickup** under **Store → Settings → Shipping and pickup**. The example has no dependency on either first-party extension.

## Payments

Implement `Sellissimo\Core\Contracts\PaymentGateway` and call `$registry->payment('my_gateway', $provider)`. Add its handle to `sellissimo.payments`. Sellissimo reserves physical stock and commits the pending order before invoking the gateway.

| Operation | Contract |
| --- | --- |
| start(order) | Create or recover one checkout session per order UUID. Return id and url. An expired result is valid only after establishing that no payment can complete. |
| outcome(order) | Verify the session with the provider. Return pending, expired, or paid. For paid, include amount as an integer, uppercase currency, and the provider payment reference. |
| supportsRefunds() | Declare whether the gateway can refund a settled payment. |
| refund(order, refund) | Use the refund UUID as the idempotency key. Return pending, succeeded, or failed after checking the provider. Recover previous outcomes across retries. |

These status strings are carried under the `status` key for checkout outcomes. The refund operation returns the status string directly. The order exposes id, site, currency, total, email, session_id, payment_ref and the frozen receipt as JSON. A refund exposes id, order_id, sku and amount. An `@all` refund covers the remaining balance, `@shipping` covers delivery, and `@charge:handle` covers the named charge, including its tax. Monetary values use integer minor units.

A gateway's webhook authenticates the originating provider, resolves the corresponding order, and invokes `app(Checkout::class)->reconcile($id)`. Core verifies the reported amount and currency before releasing downloads. The Laravel scheduler also reconciles any outstanding session.

Listen to `sellissimo.order.paid` (order ID), `sellissimo.order.refunded` (order ID, refund ID), or `sellissimo.order.shipped` (order ID, tracking). Implement idempotent listeners for external fulfillment systems. The core's payment and refund notifications run after the transaction commits.

Package authors can mix `Sellissimo\Core\Testing\ShippingProviderContract` and `PaymentGatewayContract` into PHPUnit tests and implement the fixture methods. Also exercise the gateway's own signature verification, async failure, currency validation, and refund recovery with its provider's sandbox.

The storefront quote includes stock and billing feedback. Checkout continues to reserve physical inventory atomically before invoking a payment gateway. Shipping and payment provider interfaces are unchanged.

Sellissimo emits `sellissimo.parcel.shipped` with the order ID and parcel ID after each parcel commits. The existing `sellissimo.order.shipped` event fires once, when the last physical units ship, with the order ID and final tracking number. Repeated submissions with the same request ID do not create another parcel or restock a return twice. Each shipping provider continues to quote delivery for the entire purchase.


## Carrier labels, tax providers and discount rules

Register optional capabilities on the existing registry in your addon service provider.

```php
$registry = app(\Sellissimo\Core\Registry::class);
$registry->carrier('parcel', new ParcelCarrier);
$registry->tax('regional', new RegionalTax);
$registry->discount('volume', new VolumeDiscount);
```

- A carrier implements `Sellissimo\Core\Contracts\Carrier`. Use the same handle as the shipping quote provider and enable it in `sellissimo.carriers`. The booked shipment includes its site, order ID, service, dispatch location code, corrected destination, instructions and selected physical line quantities. Use the supplied booking UUID as the idempotency key. Return the carrier reference, tracking number and HTTPS label URL. A definitive refusal before purchase can throw `BookingRejected`. For timeouts, retrying the UUID must recover the original result. Voiding the same booking twice must be safe.
- After authenticating an incoming carrier update, call `app(\Sellissimo\Core\CarrierLabels::class)->updateTracking($handle, $bookingId, $displayStatus, $occurredAt)`. Older and duplicate updates are ignored. The carrier plugin owns the webhook endpoint and verifies its signature.
- A `TaxProvider` supplies required address fields and class rates in integer basis points. Select it via `tax_settings.default.provider` in Sellissimo configuration, replacing `default` with the Statamic site handle. The core applies class inclusion settings, rounds in the order currency and persists the receipt.
- A `DiscountRule` returns the merchandise discount in minor units. Select it in a configured coupon, for example `'discounts' => ['BULK' => ['rule' => 'volume']]`. It receives the quoted lines, currency and coupon settings. Core caps the result at the merchandise subtotal and checks the configured usage limits.

## Outgoing webhooks

Give integration staff the **Manage outgoing webhooks** permission. In **Store → Settings → Integrations and system status → Outgoing webhooks**, add a public HTTPS destination, choose events and copy its one-time signing secret. Available events are `order.paid`, `order.refunded`, `parcel.shipped` and `order.shipped`. The event is recorded in the same database transaction as the order change. The scheduled reconciler sends it, records the HTTP outcome and retries failed attempts with increasing delays. Operators can pause endpoints, rotate secrets and requeue failed deliveries.

Each JSON body includes the delivery ID, event time, order ID, public number, site, currency, captured total, SKU quantities and event-specific details. Verify the `Sellissimo-Signature` header by computing `v1=` followed by the hexadecimal HMAC-SHA256 of `Sellissimo-Timestamp + "." + raw_request_body`, using the signing secret. Compare in constant time and accept only a recent timestamp. Deduplicate by `Sellissimo-Delivery`. Return an HTTP 2xx response after accepting the event. Outgoing delivery uses PHP cURL and resolves public endpoints for each attempt.

### Store activity

Shipping options can include `pay_on_delivery => true` when the service can collect payment. The store must also enable the pay-on-delivery gateway. A missing flag disables collection for that service. The buyer's selected quote determines eligibility, including on draft payment links.

The following events are available to PHP listeners and signed outgoing webhooks. PHP listeners receive one associative array with the listed fields and `order_id` when known. Events run after commit. Webhook deliveries are recorded inside the transaction and keep the same delivery ID across retries.

| PHP event | Webhook topic | Details |
| --- | --- | --- |
| `sellissimo.stock.low` | `stock.low` | SKU, previous and available quantities, configured threshold. Fires when stock falls from above the threshold into the positive low-stock range. |
| `sellissimo.stock.exhausted` | `stock.exhausted` | The same fields when a positive quantity reaches zero. Transfers between locations preserve the total. Stock events carry the originating order ID in the details when available and have a null order summary. |
| `sellissimo.return.received` | `return.received` | Return ID, purchased line key, SKU, quantity received and quantity restocked. Covers staff returns and approved buyer requests. |
| `sellissimo.product.downloaded` | `product.downloaded` | Purchased line key, SKU and cumulative download count. Emitted once when a file or ZIP is prepared and its use recorded. HEAD requests do not consume an allowance. |
| `sellissimo.order.cancelled` | `order.cancelled` | Order ID and the restock choice. Refund settlement continues to emit the existing refund event. |

The stock threshold defaults to five. Set **Low-stock threshold** in **Store → Settings → Catalog and inventory** to match your replenishment policy. Stock is shared across sites.

### Staff order details

A registered shipping method or payment gateway may also implement `Sellissimo\Core\Contracts\DisplaysOrderDetails`. Sellissimo calls `orderDetails($order, $receipt, $staff)` on the provider used for that order. Return rows with string `label` and `value` fields and an optional HTTPS `url`. Return an empty array to hide the card. Check the staff member’s permission before returning restricted information. Use the saved receipt for the service or pickup point the buyer selected. The bundled local-pickup example implements this contract.

### Merchant-configured discount rules

Enable the registered handle in `sellissimo.discount_rules`. Enabled rules appear under **Discounts → Offer type** for both coupons and automatic promotions. An existing `DiscountRule` works without settings. Implement `ConfiguresDiscountRule` to provide a label and a keyed array from `fields()`. Each field has a label and type (`text`, `integer`, `toggle` or `select`). Fields can specify `required`, a `default`, numeric limits, or select `options` keyed by stored value. The generated discount starter includes a working example.

The rule receives validated settings and eligible lines in the chosen currency. Each line’s subtotal reflects earlier offers. Sellissimo caps the discount at the remaining eligible merchandise amount. Removing a provider makes its automatic offers inactive and its coupons unavailable. Staff can still disable its saved promotions.

### Conditional payment methods

A registered payment gateway may also implement `Sellissimo\Core\Contracts\DeterminesPaymentAvailability`. Its `isAvailable(array $quote, string $site): bool` method receives the selected site and the buyer-visible quote, including currency, integer total in minor units, lines, billing address, optional shipping address and selected shipping service. Allow for incomplete addresses when a buyer is still building a cart. Use a side-effect-free check. Sellissimo checks eligibility again when placing an order. For an additional payment on an amended order, the total passed to the method is the amount to collect.

## Checkout and pricing extensions

Configure a site's permitted countries, register conditional checkout steps and resolve base unit prices for a cart or customer in the [checkout extension guide](checkout-extensions.md).

### Placement and delivery events

Subscribe to `order.placed` to ingest a newly persisted order, including a pay-on-delivery order that is ready to pack. Its details contain `gateway` and `payment_status`. A normal online order starts at `pending`, while a pay-on-delivery order starts at `awaiting_collection`. `order.paid` signals confirmed payment. Use the two events independently when deciding whether to dispatch.

`parcel.delivered` identifies each parcel by ID, number, tracking number and confirmation time. `order.delivered` fires after every physical unit has shipped and every parcel has a delivery confirmation. Staff confirmations and carrier callbacks share those transitions. A callback received before dispatch is remembered and appears when the parcel is dispatched. Repeated confirmations do not repeat those transitions. The corresponding Laravel events use the prefix `sellissimo.` and receive an array with `order_id` and the event details after the database transaction commits. Select each topic on the webhook settings screen for external delivery. Webhook consumers can deduplicate transport retries by delivery ID.

## Add settings to the Control Panel

In the extension's service provider, resolve `Sellissimo\Core\SettingsRegistry` during boot. Use `action` to link an established editor from a section. The route is a Statamic CP route name. Set the shared argument to true for a storewide editor and enforce storewide authorization on its own routes as well.

```php
app(\Sellissimo\Core\SettingsRegistry::class)->action(
    'shipping', 'Pickup locations', 'my-pickup.locations', 'manage sellissimo shipping', true
);
```

Use `section` to provide a native settings form. Give each section a unique handle and each field a unique handle within its section. Provide the field definition, inherited default, Laravel validation rules and any shared scope. The optional validation callback receives the site and submitted changes inside the store transaction. A reset is represented by a null value. A readiness callback can return a list of messages with optional authorized URLs. The merchant sees these tasks in the overview.

```php
use Sellissimo\Core\SettingsRegistry;
use Sellissimo\Core\StoreSettings;

app(SettingsRegistry::class)->section(
    'pickup-desk', 'Pickup desk', 'Instructions shown when an order is collected.',
    'manage sellissimo shipping',
    fn (string $site) => [
        'my_pickup_instructions' => [
            'field' => ['type' => 'textarea', 'display' => 'Collection instructions'],
            'default' => '', 'rules' => 'nullable|string|max:500',
        ],
    ],
);

// Read the same effective setting inside your pickup checkout step.
$instructions = StoreSettings::forSection($site, 'pickup-desk', 'my_pickup_instructions', '');
```

Core checks section permission, site access, shared scope, hosting locks and revisions before a save. Use provider-specific encrypted storage for credentials. Ordinary setting fields are available to the browser and are intended for merchant preferences.
