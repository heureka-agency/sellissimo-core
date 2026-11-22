# Catalog files and order queues

## Import products, warehouse counts and images

Open **Store → Catalog & inventory → Catalog import and export**, select the site and collection, and download the CSV template or export the current catalog. The export includes available stock by warehouse for staff with inventory permission. **Export with product images (ZIP)** includes the referenced image files for staff with access to the product image container.

Existing CSV templates continue to work. An empty cell preserves the current value. Use `__CLEAR__` to clear an optional product field. Each SKU appears once across the batch. Rows for variants of the same product share a product handle and must agree on product-level fields.

| CSV column | Example cell value | Behavior |
| --- | --- | --- |
| `categories`, `tags` | `["tea","gifts"]` | Ordered term handles in the matching product taxonomy. New terms appear in the preview and require term creation permission. |
| `gallery` | `["mugs/front.png","mugs/back.png"]` | The first image is the product cover. The product image container supplies the files. |
| `variant_image` | `mugs/red.png` | Image for the current variant. |
| `attributes` | `{"Color":"Red","Size":"Large"}` | Named variant choices. |
| `stock` | `{"main":12,"north":4}` | Available units to sell at existing warehouse codes. Outstanding reservations are already deducted. Omitted warehouses keep their balances. Enter zero to empty a warehouse. |

To import new images, place the CSV files at the root of a ZIP. Place an image referenced as `mugs/red.png` at `images/mugs/red.png`. The file must be a JPEG, PNG, WebP, GIF or AVIF. The importer checks its content, requires image upload permission for new files, and flags existing files with different contents. A matching existing file can be reused. Product images, terms and stock changes take effect when applying the preview.

A batch accepts 20,000 rows and 16 MiB of CSV data. A ZIP accepts 100 MiB of expanded data and 10 MiB per image. Server upload limits also apply. Large exports retain their split CSV parts. Import those parts together as a ZIP when the batch fits these limits.

The preview shows changed rows, conflicts, new assets and terms, and the old and new warehouse balance. Resolve all preview conflicts before applying. Large imports save up to 100 complete products per step. Pause between steps or reopen a recent preview under your account to resume. If a product or warehouse balance changed since preview, review a fresh preview. Completed steps remain recorded. Repeated confirmation of a completed step leaves quantities and stock history intact. The scheduled cleanup removes staged files and previews after seven days.

### Formatted descriptions

In the product editor, use the description toolbar for headings, lists, links and inline images. Pick images from **Product images** and enter alternative text that describes what the image contributes to the description. The gallery and its cover are edited separately.

| CSV column | Cell contents |
| --- | --- |
| `description` | Markdown text for a legacy or plain-text import. |
| `description_bard` | A JSON list of Bard nodes. Export an edited product to see its current structure. |

Populate one description column per product, including across its variant rows. An empty cell preserves the current description. Use `__CLEAR__` to remove it. Here is a single `description_bard` cell's value before ordinary CSV quoting.

```json
[{"type":"paragraph","content":[{"type":"text","text":"Solid oak."}]},{"type":"image","attrs":{"src":"asset::sellissimo_products::details/oak.png","alt":"End grain of the oak board"}}]
```

A CSV can reference an image already present at `details/oak.png` in the product image container. To transfer the file to another store, use **Export with product images (ZIP)**. The ZIP includes `images/details/oak.png` alongside the CSV. Its optional root `image-alt.json` maps container-relative paths to asset alternative text, for example `{"details/oak.png":"Oak grain"}`. Import applies that metadata to newly created assets and preserves existing asset metadata. The inline image's own alternative text travels in the Bard JSON.

## Filter orders by an order field

In **Store → Orders → Filters**, select a supported order-blueprint field, choose or enter the exact value, and apply the filter. Text matching ignores case and surrounding spaces. Use **Columns** to add and reorder that field, then save the queue under **Saved views**. The queue reflects the current operational value after staff corrections. Attachments are available in the order detail.

**Export CSV** keeps the existing order-summary format. Selected custom field columns appear after the standard columns. **Export order lines** exports every matching order with its currency, SKU, purchased quantity, discounts, tax, current total and settled refund. Line keys distinguish personalized versions of the same SKU. Shipping and order charges have their own rows. Whole-order refunds are allocated across remaining balances. Standalone amendment credits and residual refunds appear as adjustments so settled amounts reconcile.

For dispatch, select printable orders and choose **Print warehouse pick list**. The list groups outstanding quantities by warehouse and SKU and includes the order reference for each quantity. Shipped parcels reduce the quantities, and untracked physical goods appear in a separate group. Individual packing slips still carry each customer's address and personalization.
