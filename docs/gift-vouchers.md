# Gift vouchers

Enable **Gift vouchers** for a site under **Store → Settings → Checkout and customers → Wishlists, vouchers and back-in-stock alerts**. A voucher belongs to its issuing site and currency and has no automatic expiry.

## Selling and issuing vouchers

In the product blueprint’s Shipping section, enable **Gift voucher**. Set the regular price of each SKU to its face value in the site's currency. Use variants for different denominations. A voucher is delivered electronically. Its product tax class controls tax on the purchase. A buyer can enter a recipient email or leave that field empty to receive the voucher themselves. Each unit issues its own code after payment is confirmed. The scheduler retries undelivered email.

Give staff **Manage gift vouchers** to issue vouchers directly in **Store → Discounts & vouchers → Gift vouchers**. Staff enter an amount and a recipient. The list displays the last four characters of each code, recipient, original amount, remaining balance and delivery state. Select a masked code to review balance movements and associated orders. The recipient’s email includes a balance-check link. Treat the full code like cash.

## Paying and refunding

Enter one voucher at checkout or on a draft payment link. The shop applies eligible merchandise discounts, shipping, tax and fees before the voucher. Its balance can pay for those charges up to the eligible total. Gift voucher products use their fixed price and require other payment. For a partially covered order, the buyer chooses a payment method for the remainder. Placing the order reserves the voucher balance. Expiring that payment releases the reserved funds. Existing vouchers can be refunded to their original balance if new sales for the site are disabled.

Partial refunds, cancellations, returns and amendment credits restore the original voucher’s proportional share. The rest returns through the original external captures. Cumulative rounding reconciles to the exact amounts originally paid. A positive amendment or exchange balance is collected through its payment link. Issued voucher products can be refunded from their original order. The voucher’s remaining balance limits the refund on its purchase. The order details show that unused value. Staff should review payment-provider refunds initiated outside Sellissimo when recipients have already spent part of the refunded value.

Sales reports separate issued voucher value, voucher sales, vouchers used for payment, refunds credited back to a voucher and cash receipts. Issued voucher sales stay outside the merchandise revenue column.

## Storefront API

Submit a single `voucher` string with the cart quote and checkout requests. A successful quote includes `voucher.ending`, `voucher.amount`, `amount_due` and the unchanged gross order `total`, all monetary amounts in minor units. Send the quote token to checkout to detect changes to the available balance. GET the voucher balance form at `/api/sellissimo/v1/vouchers/balance?site=default`; POST the code in the request body with session CSRF protection. A JSON response contains only the last four characters, available amount and currency.
