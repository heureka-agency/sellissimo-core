# Store operations

Set up currency, checkout, delivery, tax, payment methods, email and order defaults in **Store → Settings**. [Merchant settings guide](store-settings.md)

## Import products and variants

Choose **Store → Catalog & inventory → Catalog import and export**, download the header template, select the site and collection, and upload a UTF-8 CSV or a ZIP with CSV files and product images. The preview lists the action for each row. Resolve every conflict, then apply the import. You can pause between batches and resume from a saved preview for up to seven days. If a target entry changes before confirmation, preview the file again. The import requires the Sellissimo import permission and the corresponding Statamic create, edit and publish permissions.

| Columns | Meaning |
| --- | --- |
| `sku`, `product_handle`, `variant` | Match by SKU within the selected site. Use the same product handle for all variants and a distinct SKU and variant label for each. |
| `title`, `summary`, `description`, `brand`, `product_type` | Product-level content. Enter the title on the first row of each new product. Shared values across its rows must agree. |
| `price`, `sale_price`, `cost` | Decimal amounts in the selected site's currency. New SKUs need a regular price. A sale price must be lower. |
| `barcode`, `mpn` | Product or variant identifiers. Format barcode columns as text in the source spreadsheet to retain leading zeros. |
| `weight_kg`, `length_cm`, `width_cm`, `height_cm` | Package measurements for this SKU. A value on a variant overrides its product default. |
| `sale_starts`, `sale_ends`, `unit`, `unit_quantity` | Inclusive sale dates use YYYY-MM-DD. Supported comparison units are kg, l, m and item. Enter the unit and quantity together. |
| `country_of_origin`, `hs_code`, `service`, `featured`, `published` | Use a two-letter country code, a 6–12 digit customs code, and 1 or 0 for flags. New entries start as drafts by default. |
| `seo_title`, `seo_description` | Product page metadata. |

A CSV can contain just the columns you need. Keep a cell blank to preserve its value or enter `__CLEAR__` in an optional field to remove it. The importer preserves omitted fields, other currencies and sibling variants. A batch supports up to 20,000 rows and 16 MiB of CSV data. [Catalog files, warehouse counts and image ZIPs](catalog-and-order-workspace.md)

```csv
sku,product_handle,variant,title,price,barcode,published
LINEN-NAT,linen-towel,,Linen towel,18.00,001254,1
MUG-SAND,studio-mug,Sand,Studio mug,32.00,009761,1
MUG-MOSS,studio-mug,Moss,,32.00,009762,1
```

## Locations and stock

Tracked physical products and variants appear in **Store → Catalog & inventory → Inventory** at zero until their first stock count. Open **Adjust stock** beside a saved SKU in the product editor to change its quantity. The low-stock widget includes tracked items without an opening balance.

Open **Store → Catalog & inventory → Inventory → Locations and history**. Add a location and select an SKU to see its balances, record a count, or transfer available units. A location count records the reason and the staff member. Transfers post a debit and credit together. The available quantity on the storefront is the sum across locations.

Upgrading assigns existing available stock to **Main location** and records opening balances. Checkout reserves main stock first, then other locations in handle order. A cancellation releases stock to its original locations. The order's fulfillment section shows its allocations. Returns restock at Main. Exchange approval reserves the replacement in its original stock locations. The movement history records the operation, resulting location balance, actor and associated order.

## Customers and address books

Under **Store → Customers**, switch between registered accounts and previous guests and search by name or email. Select a customer to see purchases on the selected site and add or edit labelled addresses. Each address can be the default for billing, shipping or both. A registered buyer can edit the same book from **My orders → Manage address book**. Historical order addresses remain on their original receipts. Guest profiles stay separate from registered accounts, including when their email addresses match.

## Draft shipping

Open **Store → Orders → Draft orders → Create draft** to enter the delivery destination before choosing a product. For a physical order, use the billing address or enter a separate recipient, company, phone, street, apartment, city, region, postcode and country. Add delivery instructions such as an entrance or gate code. Treat instructions as visible to the buyer and fulfillment team.

Choose a shipping provider's quote or select **Set a custom delivery rate** for a one-off service such as a local courier. Enter a service label and a decimal charge, including zero for free delivery. Use **Save draft** while details, stock or rates are outstanding. **Send payment link** rechecks the order and emails the buyer. The customer payment link displays the agreed service, charge, destination and instructions. The packing slip carries the recipient and delivery instructions. A download or service needs no shipment.

## Sales reports

Choose **Store → Sales reports**, a date range and optionally a site. Both dates are inclusive in the application's timezone. Switch between product and variant breakdowns. Each currency has its own totals.

Gross merchandise sales are the pre-discount merchandise amount excluding tax. Net merchandise sales subtract discounts and refunded merchandise. Net receipts also include net tax and delivery. Total refunds include the tax and shipping portions. A line refund allocates its tax proportionally. A whole-order refund distributes the remaining amount across its remaining lines and delivery. Refund-only periods can have negative net sales.

Sales appear when Sellissimo confirms payment. A refund appears when its payment provider confirms success or when authorized staff record an external refund. Historical orders use recorded payment activity when available, with order creation as the fallback. Historical refunds without a recoverable confirmation date appear separately. New receipts preserve the product identity and name for reports. For legacy receipts Sellissimo matches the SKU and purchased title where a unique catalog match exists, otherwise it groups by the original SKU.

## Returns and exchanges

In **Store → Orders → Returns**, select an order number to open a case. The queue shows each request’s status and next action. The case panel contains the buyer’s reason, return history, and permitted operations. Close the panel to return to the same row.

A buyer opens a shipped order from account history or a signed receipt, chooses **Request or track a return**, selects a delivered item, and gives the quantity and reason. They can request a return for refund review, a replacement of the original SKU, or an available alternative at the same unit price. The requested quantity is set aside from other open requests and direct staff returns.

In **Store → Orders → Returns**, approve or decline the request and send instructions with **Message buyer**. Approval reserves the replacement. If the item is unavailable, choose another available replacement or switch to refund review. Changing an approved replacement swaps its stock allocation. When the item arrives, record receipt and the quantity fit to restock. Dispatch with tracking or switch the received exchange to a linked refund. Refund staff can issue the available amount from the return. Buyers see the decision and message history on their signed return page. Each update is emailed in the buyer’s store language. Reconciliation retries undelivered messages in order.


## Fulfillment and payment corrections

Before the first dispatch, fulfillment staff can choose **Correct delivery address** on the order. The packing slip and carrier use the updated destination. The activity log retains the previous and new addresses, names the editor and flags a rate or tax change for review. Void any open carrier label before correcting its destination. The buyer's financial receipt retains the original checkout snapshot.

A store with an enabled carrier extension can book a label from **Ship a parcel**, select its dispatch location, print it, void it or dispatch the labeled quantities. If a booking times out, retry the same request to retrieve its result. After dispatch, carrier tracking updates appear on the order and buyer receipt.

For cash or bank refunds completed outside the store, select **Record manual refund** on a paid order. Choose an item, delivery or remaining balance, then enter a reason and reference. Sellissimo records settlement immediately and includes the amount in the sales report.

## Site queues and order numbers

**Store → Orders** and **Draft orders** have a site selector on multisite installations. Search, shipment tabs, pagination, CSV export and the order’s back link use the selected site. Before issuing the first order, open **Store → Settings → Orders, returns and downloads** and set **First order number** to continue a numbering series. New orders receive a unique sequential number across sites. Existing Sellissimo orders keep their published references. Full order IDs and signed receipt links continue to work.

## Amend a paid order

Open an undispatched order and choose **Change order items**. Staff need the **Amend paid orders and settle balances** permission. Choose a product or variant by name or SKU, set the quantity, complete its personalization and line fields, and record the buyer’s reason. For physical items, review the delivery address and choose a quoted service. The new destination takes effect when the change settles. **Review changes** recalculates merchandise, promotions, delivery and tax with the current catalog. An expired coupon can be removed. The preview shows the new total and the amount to collect or return.

Confirm an even exchange to update the packing slip immediately. For a positive balance, select an online payment gateway and email or copy the amendment payment link. The buyer reviews the changes before visiting hosted checkout. For a credit, Sellissimo refunds the original payment sources. Enter an offline refund reference only after returning that amount externally. Staff can retry a declined amendment refund. Supplemental payments are separate captures and a later full refund can span several captures.

New stock is reserved when the amendment is confirmed. Dispatch and further changes wait for settlement. A payment link that has never been opened expires after 24 hours. Once payment has started, the gateway determines its outcome. Decreases release stock on application. The amendment retains the previous receipt, proposed receipt, reason and author. Reports assign the financial change to its settlement period.

## Checkout recovery

**Store → Orders → Checkout recovery** lists inactive carts and shows which reopened carts became orders. Support staff can email a reminder to a buyer who selected the reminder checkbox and supplied an email when updating their cart. Opening the email asks the buyer to confirm before moving the cart to the device. The link expires after seven days and can be claimed once. Account carts require their owner to sign in.

In **Store → Settings → Emails and notifications**, set the inactivity interval (initially 60 minutes), the retention period (initially 90 days), and whether to send automatic reminders to buyers who opted in. These settings apply across all sites. For a custom storefront, set the selected site’s recovery URL. The scheduled task prunes stale shopping carts and keeps drafts and carts attached to orders.

## Customer-facing mail

Receipts, draft payment invitations, shipment notices, account claims, amendment links and recovery reminders use the order or cart site’s language. English and French are included. Override `mail/layout.blade.php`, an individual HTML message or the shared plain-text template under `resources/views/vendor/sellissimo/mail`. The shared plaintext view is `text.blade.php`. On the host, place translated subjects and messages in `lang/vendor/sellissimo/{language}/mail.php`. Run `php artisan vendor:publish --tag=sellissimo-translations` to copy the supplied translations. The site name is available to every template as `$brand`.

## Import a Cargo store’s history

Copy the source store’s Cargo content into a local directory containing `orders/`, `discounts/`, or both. Import the catalog and copy purchased files to Sellissimo’s private disk first. Ensure destination Statamic accounts already exist. Back up the commerce database before applying an import.

```sh
php please sellissimo:import-history /path/to/content/cargo --site=default --map=/path/to/map.json
php please sellissimo:import-history /path/to/content/cargo --site=default --map=/path/to/map.json --apply
```

The first command validates and rolls back. The second commits the batch. Repeating an unchanged import skips records, and collisions or changed source records roll back the batch. Site subdirectories select the site for orders. Product-specific discounts also need matching entry IDs or a discount_products mapping. Use the site’s original currency configuration for records that omit a currency. The command reads Cargo’s file-backed format. Export database-backed records to the same format first.

The optional JSON map links original account IDs to destination account IDs and original product or variant references to SKUs. An example follows.

```json
{
  "customers": {"previous-account-id": "destination-account-id"},
  "discount_products": {"previous-entry-id": "destination-entry-id"},
  "products": {
    "previous-entry-id|large": {"sku": "SHIRT-L"},
    "discontinued-book": {"sku": "GUIDE", "title": "First edition", "digital": true, "download": "guides/first-edition.pdf"}
  }
}
```

Guest orders stay guests. Each import keeps the original source record, historical amounts, order number, customer link and shipment information. Dated refund events must reconcile with Cargo’s refund total. The importer records these credits without contacting the gateway, preserves the originating site for discounts and keeps downloads closed for returned orders. It leaves stock unchanged and suppresses historical receipt emails. Sellissimo recognizes Cargo’s amount, percentage and free-shipping discounts. Customer-restricted offers use the customer ID mapping. Unresolved accounts and custom discount types produce a review error. Historical pending payments remain archival. Resolve them in the originating payment system.

## Find products on a draft

Choose **Product or variant** on a draft line. Search by product name or SKU and use the page controls to browse twelve results at a time. Selecting an item loads its personalization fields and delivery type. Reopening a draft loads its saved selections.

## Deliver a digital pack

In a product’s **Pricing and variants** tab, add the files from the store’s private download disk. Set the allowed number of downloads and the access period beside the files. For variants, set them on each row. An empty field inherits its value from **Store → Settings → Orders, returns and downloads**. Enter 0 to allow unlimited downloads or an unlimited access period. Each purchased line keeps its terms. The access period starts on payment confirmation. Previously saved single-file products keep delivering that file directly. A ZIP includes the filenames from storage, with numbered prefixes when two filenames match.

The purchase records the files and limit. One successful request uses one download from the purchased line, including when the buyer requests a complete pack. Quantity on the same line does not multiply the allowance. Staff can see the count on the order, and buyers can see how many downloads remain on their receipt. A file missing from storage does not consume an allowance.

The Cargo importer carries over each line’s `download_count`. It reads the limit from the source product or variant when available, or from the mapped Sellissimo product. For source products stored elsewhere, provide the original policy and private paths in the product mapping:

```json
{"products":{"original-entry-id|large":{"sku":"GUIDE-L","download":["guides/book.pdf","guides/examples.zip"],"download_limit":3}}}
```

The upgrade also reads stored Cargo import records to restore historical counts. Downloads served by older Sellissimo versions did not record counts.

## Restrict a promotion to selected customers

In **Store → Discounts & vouchers → Discounts**, open a coupon or automatic offer. Under **Eligible customers**, select registered customers by name or email. The selected accounts can receive the offer. Leaving the list empty makes the offer available to all otherwise eligible buyers. Usage limits still apply. Guest checkout cannot use a restricted coupon by entering a member’s email. For an assisted sale, select the customer’s account on the draft.

## Generate and bulk edit product variants

Open a product’s **Pricing and variants** tab and choose **Generate variants**. Enter a SKU prefix and one or more attributes with comma-separated values. For example, two colors and three sizes produce six combinations. Review the count and sample SKUs, then add the rows. Existing attribute combinations are skipped, and SKU conflicts must be resolved before saving. Newly generated rows need a price to be sellable.

Choose **Bulk edit variants**, select the rows to change and enter the new selling price, sale price, internal cost or packaged weight. For prices, choose the currency. Apply the change, review the rows, then save the entry through Statamic. The standard row editor remains available for individual changes. Custom blueprints that copied the variant grid can use the `sellissimo_variants` fieldtype with the existing nested fields.

### Cancellation before dispatch

Open the paid order and choose **Cancel order**. Staff with refund permission can enter the reason, return the remaining physical units to their original stock locations, and email the buyer. The cancellation removes the order from dispatch immediately. The refund covers the outstanding amount across the original payment and subsequent charges. If the money was already returned offline, enter its reference. Pending refunds are reconciled automatically. A definitive refund failure exposes **Retry cancellation refund** on the cancelled order. Repeating the operation preserves the original stock adjustment. Void a booked label or settle a pending amendment before cancelling. Shipped items follow the returns workflow.

### Export and update the catalog

In **Store → Catalog & inventory → Catalog import and export**, select a site and collection and choose **Export current products and variants**. The file uses the import template, includes editable products, and lists each SKU’s prices in the selected site's currency. Edit the prices and upload the CSV to preview and apply the changes. Larger exports download as a ZIP of CSV files that fit the importer’s size and row limits. Upload each file separately. Spreadsheet-sensitive text is prefixed with an apostrophe for safe editing.

### Buyer language and delivery choices

Payment links, receipts and the bundled storefront use the originating Statamic site's language. English and French are included. To customize labels or add a language, publish `sellissimo-translations` and edit `lang/vendor/sellissimo/<locale>/shop.php`. Merchant-authored titles, shipping service names and delivery instructions are displayed as entered.

Pay on delivery requires a participating shipping service. For merchant-entered draft rates, select **This service collects payment on delivery** when appropriate. For flat rates, use **Store → Settings → Shipping and pickup**.


### Order views and assisted sales

In **Store → Orders**, use the Payment filter for pending, partial or settled refunds. Choose **Open returns** to find unresolved requests. Use **Filters** to apply a date range, then open **Saved views** and name the current queue. Views belong to the signed-in staff member. Select printable orders to open a single document with a page break between packing slips.

In a draft, **Reserve stock until** holds tracked items until the local date and time you choose. Leaving the field empty keeps stock available to other buyers. Saving changes adjusts the held quantity. Clearing the date or cancelling releases the allocation to its original locations. Checkout takes over the draft’s allocation. Quotes, checkout, draft saves and exchange allocation release elapsed holds. The scheduled five-minute reconciliation also releases them on an idle store. An expired hold leaves the draft open and availability is checked again at payment.

In a draft, enter a custom unit price for a catalog item or use **Add service** for a one-off charge. Prices use the draft currency. A service uses the standard tax class and has no stock or downloads. The payment link uses the saved unit prices. The mobile footer shows the estimated total until the address and delivery are ready.

Sales reports compare a selected date range with the immediately preceding range of the same length. Order counts use the payment date. Daily net merchandise sales reflect refunds on the settlement date and applied order changes on the change date. Each currency has its own graph and daily amounts table.

### Returns, refunds and guest accounts

In **Store → Orders → Returns**, store managers can set the number of days buyers have to request a return for the selected site. Leave the field blank for unlimited returns. Mark a physical product **Final sale** to exclude it. Each purchased line keeps its terms, including when an order is later edited. With a deadline, buyers can return confirmed delivered units immediately. When one line is split over several parcels, its deadline runs from delivery of its last unit, through the end of the last day in the application timezone. The receipt, request form and staff order show the deadline. Earlier orders retain their existing terms.

Buyer receipts show original value, extra charges, settled refunds and net paid. The store emails a receipt link after a refund settles. A refund spread over multiple payments produces one notice after processing finishes. The reconciliation command retries outstanding notices.

Open **Customers → Guests**, choose a contact and select **Invite to claim orders**. The email invitation lasts 24 hours. The buyer signs in with the same email and confirms the listed orders. Only that site's guest purchases selected when the invitation was sent can be linked. Choose custom sign-in and registration pages under **Store → Settings → Checkout and customers**. The bundled invitation uses Statamic’s sign-in and, on Pro, registration forms otherwise.

### Made-to-order goods and refunds for returns

Turn off **Track inventory** on a physical product to sell it without a stock limit. Delivery, fulfillment, tax and returns continue to apply. Existing orders use the inventory tracking setting recorded at purchase.

In **Store → Orders → Returns**, approve a refund request and receive the merchandise. **Issue refund** shows the refundable amount for the received units. Confirm a provider refund, or select **Record manual refund** after paying the buyer offline and enter the payment reference and reason. The buyer’s return page shows the pending or settled amount. Previously issued order and line refunds reduce the balance available. Historical refunds without a return reference retain their original order association.

**Store → Sales reports** counts each normalized purchase email once across the selected sites and currencies. A returning customer first paid before the start of the period. Guest orders count. The previous period has the same length.

In a product or variant’s private download field, upload a new file or select a file already on the private disk. Each upload gets its own path, including a replacement with the same filename. Save the entry to use the new edition for future purchases. The configurable `download_upload_kb` limit defaults to 51200 KiB and the host’s PHP upload limits also apply.

Under **Orders → Columns**, choose fields, change their order, and apply them. Open **Saved views** to save the queue. **Use these columns by default** changes your own initial layout. A site can also set role defaults in the `order_columns_by_role` configuration, for example `['warehouse' => 'order,fulfillment,site,date']`. The first configured matching role applies until the staff member chooses a personal default. The order number always opens the order. Manual shipments accept an optional public HTTPS carrier tracking URL. Carrier addons can supply `tracking_url` alongside the booking reference, tracking number and label URL.

### Correct a guest receipt email

Grant **Correct guest order emails** to the support staff responsible for verifying buyers. On a guest order, use **Correct guest email**, confirm the new address and record how you verified the buyer. The activity log records who made the change. Sellissimo sends a new receipt link to the new mailbox and retries delivery through the reconciler if mail is unavailable. Previously issued guest receipt, download and return links expire. Account ownership and the purchase snapshot are preserved.

### Update staff-only order fields

Add internal fields to the Sellissimo order blueprint and grant **Edit staff-only order fields**. Open **Edit staff fields** on the order to record a purchase order, production stage, date or private attachment. Values follow the blueprint’s validation rules. Each edit is revision-checked and recorded in the order activity. The buyer’s receipt retains the details recorded at purchase.

### Dispatch and restock locations

The fulfillment card shows the unshipped stock reserved at each location. Pick a dispatch location and select units reserved there. Ship a separate parcel for each origin. To move the picking assignment, choose **Reassign reserved stock** and a destination with available units. The reassignment releases the reservation at the original location and reserves the same quantity at the destination. The total sellable stock stays consistent. Dispatch or void an open carrier label before reassigning its stock. Printed parcel slips include the dispatch location.

When receiving a return, select **Restock at** for the units fit for resale. The incoming stock movement records that location. Older manual parcels have no recorded origin. For those parcels, Sellissimo uses the original reservation movements to estimate remaining warehouse availability. Orders created before location tracking use Main.

### Chargebacks in sales reports

**Sales reports → Net receipts** includes chargeback deductions, reversals and reported dispute fees in the period they occurred. Merchandise sales and refund totals remain independent of these payment movements. On the order, open **Payment disputes** to see dates and amounts. Stripe supplies each balance transaction in its settlement currency, including any associated fee. Mollie supplies the disputed principal in the payment currency and the creation and reversal dates. Other processing fees and bank payouts are outside these totals.

After upgrading, reconcile earlier disputed orders with the provider to retrieve their original movements. Use the payment refresh action when available, or the provider command `sellissimo:stripe:reconcile` or `sellissimo:mollie:reconcile` with the order UUID. The report identifies historical disputes awaiting reconciliation and movements missing a provider date. Replaying a callback or a refresh is safe.


### Exchanges and return shipping

In **Returns**, select **Return shipping details** to upload a private PDF or share a carrier label URL, tracking number and tracking link. Buyers get an email linking to their return. Private PDF links expire and follow the order’s customer-access rules. The dedicated Laravel disk `sellissimo_return_labels` can be configured for shared private storage when using several application servers.

Approving a replacement reserves its stock and freezes its price and tax. An exchange for the same SKU, or one at the same unit price and tax class, keeps the original purchased terms. Other replacements use their current merchandise price and tax, including applicable automatic offers. The returned units are credited at their original paid value including allocated discounts and tax. The final credit uses the quantities physically received, including rounding across split returns. Earlier standalone refunds against the original item reduce its remaining credit. For example, a $22 item with a $10 line refund gives a $12 credit toward a $13.20 replacement, leaving $1.20 to collect. An open exchange reserves that credit. Pending or unallocated provider refunds require review before settlement or dispatch. The original delivery and order-wide fees remain on the order.

After receiving the goods, staff with refund permission can **Settle exchange balance**. A positive balance creates a payment link on the buyer’s return page and emails them. A credit uses the existing refund gateway, or staff may enter an offline refund reference. Dispatch becomes available after the payment or refund is confirmed. An expired, uncollected link can be reissued. Price adjustments appear against the original order line and in the payment history.

For **Dispatch exchange**, select a warehouse, the number of reserved units to send and the parcel tracking number. If units were reserved at several warehouses, dispatch each parcel separately. The request stays received until every unit has shipped. The buyer sees quantities and tracking for each parcel. After the first dispatch, the replacement cannot be substituted or converted to a refund request.

Sales reports transfer the shipped quantity and its merchandise value to the replacement SKU and product on the parcel’s dispatch date. Payments and refunds keep their settlement dates. Historical parcels retain their known tracking numbers. Older shipment origins and dispatch dates that the store never recorded are left blank.

## Catalog batches and warehouse picking

For image ZIPs, structured catalog CSV fields, stock by location, resumable imports, custom order queues, item-level exports and warehouse pick lists, see the [catalog and order workspace guide](catalog-and-order-workspace.md).

## Staff alerts and optional storefront features

In **Store → Settings → Checkout and customers → Wishlists, vouchers and back-in-stock alerts**, choose a site. Turn on wishlists, back-in-stock alerts, or gift vouchers independently. For each staff alert topic, enter recipient mailboxes separated by commas. New-order messages include the payment state. Low-stock and sold-out alerts follow the total available across warehouses. A transfer between locations keeps that total constant. A new threshold crossing can send another alert. The scheduler sends and retries pending mail.

The **Store activity** dashboard widget shows recent orders, settled refunds by currency, and new and returning buyers for the selected site. Choose a 7-, 30-, or 90-day period. Staff see data covered by their order and sales permissions. To hide the automatic widget, set `activity_widget` to false in the Sellissimo configuration.

When wishlists are enabled, a guest can save individual variants. Signing in combines the guest's list with the customer's list on that site. Current availability and pricing are displayed each time the list is opened. Opening a saved variant returns the buyer to its product page to choose personalization or artwork.

On a sold-out product, **Email me when this variant is back** starts a confirmation by email. The buyer confirms on the linked page. One notification is sent after that published variant becomes available. Every alert contains an unsubscribe link. Staff with inventory access can see the confirmed number waiting per SKU under **Store → Catalog & inventory → Back-in-stock demand**.
