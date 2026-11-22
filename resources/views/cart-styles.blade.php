<style>
.cart-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(15rem, 19rem); align-items: start; gap: clamp(1.25rem, 4vw, 3rem); }
.cart-layout .cart-line { min-width: 0; border-bottom: 1px solid var(--ss-border); padding-block: 1.25rem; }
.cart-layout .cart-line:first-of-type { padding-top: 0; }
.cart-layout .cart-line .row, .cart-layout .ss-total { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.cart-layout .cart-line-title { font-size: 1.125rem; margin: 0; }
.cart-layout .cart-line-price, .cart-summary .ss-total { font-variant-numeric: tabular-nums; }
.cart-layout .cart-line small { display: block; overflow-wrap: anywhere; }
.cart-actions, .cart-actions form { display: flex; flex-wrap: wrap; align-items: end; gap: .65rem; }
.cart-actions { margin-top: .85rem; }
.cart-actions input[type="number"] { width: 4.5rem; }
.cart-summary { position: sticky; top: 1.25rem; }
.cart-summary h2 { margin-top: 0; }
.cart-summary .ss-button { width: 100%; justify-content: center; }
.cart-summary .ss-total { margin-block: 1rem .5rem; }
.cart-summary .ss-muted { font-size: .875rem; }
.cart-unavailable { margin-block-end: 1rem; }
#sellissimo-cart [hidden], #sellissimo-cart[data-enhanced] [data-cart-submit] { display: none; }
#sellissimo-cart [data-cart-status]:empty { margin: 0; padding: 0; border: 0; }
@media (max-width: 48rem) {
    .cart-layout { grid-template-columns: minmax(0, 1fr); }
    .cart-summary { position: static; }
}
</style>
