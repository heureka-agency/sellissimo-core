.ss-store {
    --ss-font: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --ss-canvas: #f6f7f8;
    --ss-surface: #ffffff;
    --ss-subtle: #f1f3f5;
    --ss-text: #202329;
    --ss-muted: #525a65;
    --ss-border: #d8dce1;
    --ss-control-border: #9199a4;
    --ss-accent: #28303b;
    --ss-accent-text: #ffffff;
    --ss-focus: #2866b9;
    --ss-danger: #9f2727;
    --ss-radius: .65rem;
    color-scheme: light;
    font: 100%/1.55 var(--ss-font);
    color: var(--ss-text);
    background: var(--ss-canvas);
    margin: 0;
    min-height: 100vh;
    overflow-wrap: anywhere;
    -webkit-font-smoothing: antialiased;
}
.ss-store *, .ss-store *::after, .ss-store *::before { box-sizing: border-box; }
.ss-store [hidden] { display: none !important; }
.ss-frame { width: min(76rem, 100%); padding-inline: clamp(1rem, 4vw, 3rem); margin-inline: auto; }
.ss-main { padding-block: clamp(1.6rem, 4vw, 3.25rem) clamp(2.5rem, 6vw, 5rem); min-height: 62vh; }
.ss-main.ss-narrow { max-width: 46rem; }
.ss-main.ss-document { max-width: 60rem; }
.ss-store h1, .ss-store h2, .ss-store h3, .ss-brand { color: var(--ss-text); line-height: 1.19; font-weight: 650; }
.ss-store h1 { font-size: clamp(1.95rem, 4vw, 3rem); letter-spacing: -.045em; margin: 0 0 1.25rem; }
.ss-store h2 { font-size: clamp(1.23rem, 2vw, 1.55rem); letter-spacing: -.03em; margin: 0 0 .9rem; }
.ss-store h3 { font-size: 1.05rem; letter-spacing: -.015em; margin: 1.1rem 0 .6rem; }
.ss-store p { margin: .65rem 0; }
.ss-store small, .ss-muted, .ss-store dt { color: var(--ss-muted); }
.ss-store small { font-size: .875rem; }
.ss-store a { color: var(--ss-accent); text-underline-offset: .18em; text-decoration-thickness: 1px; }
.ss-store a:hover { text-decoration-thickness: 2px; }
.ss-store :is(a, button, input, select, textarea, summary):focus-visible {
    outline: 3px solid var(--ss-focus); outline-offset: 3px;
}
.ss-store .ss-skip {
    position: absolute; top: 0; left: .75rem; z-index: 3; padding: .65rem 1rem;
    background: var(--ss-surface); transform: translateY(-150%); border-radius: var(--ss-radius);
}
.ss-store .ss-skip:focus { transform: translateY(.35rem); }
.ss-header { background: var(--ss-surface); border-bottom: 1px solid var(--ss-border); }
.ss-header-inner, .ss-footer-inner, .ss-nav, .ss-actions, .ss-store .row, .ss-store form.inline {
    display: flex; align-items: center; flex-wrap: wrap; gap: .65rem 1rem;
}
.ss-header-inner { justify-content: space-between; min-height: 4.5rem; padding-block: .85rem; }
.ss-store .ss-brand { font-size: 1.2rem; font-weight: 720; letter-spacing: -.035em; text-decoration: none; max-width: 100%; }
.ss-nav { gap: .25rem; }
.ss-nav a { display: inline-flex; align-items: center; min-height: 2.65rem; padding: .4rem .75rem; border-radius: .45rem; text-decoration: none; color: var(--ss-muted); font-size: .95rem; font-weight: 550; }
.ss-nav a:hover, .ss-nav a[aria-current] { background: var(--ss-subtle); color: var(--ss-text); }
.ss-nav .ss-nav-cart { border: 1px solid var(--ss-border); color: var(--ss-text); }
.ss-footer { border-top: 1px solid var(--ss-border); }
.ss-footer-inner { color: var(--ss-muted); font-size: .875rem; padding-block: 1.25rem; }
.ss-footer-inner span { margin-inline-end: auto; font-weight: 600; }
.ss-page-head, .ss-context { margin: 0 0 1.5rem; }
.ss-context { font-size: .9rem; }
.ss-store .row { justify-content: space-between; }
.ss-panel, .ss-narrow .ss-form-panel, .ss-store .catalog-product, .ss-store .checkout-summary, .ss-store .checkout-content {
    min-width: 0; padding: clamp(1rem, 2.5vw, 1.65rem); background: var(--ss-surface);
    border: 1px solid var(--ss-border); border-radius: var(--ss-radius);
}
.ss-panel + .ss-panel, .ss-store .ss-block { margin-block-start: 1.25rem; }
.ss-panel > :first-child, .ss-store .checkout-content > :first-child { margin-block-start: 0; }
.ss-panel > :last-child, .ss-store .checkout-content > :last-child { margin-block-end: 0; }
.ss-store .ss-empty { padding: 1.75rem; text-align: center; border: 1px dashed var(--ss-control-border); border-radius: var(--ss-radius); color: var(--ss-muted); background: var(--ss-surface); }
.ss-store .ss-alert, .ss-store [role="alert"], .ss-store [role="status"] {
    padding: .8rem 1rem; border: 1px solid var(--ss-border); border-inline-start: 3px solid var(--ss-focus);
    border-radius: .45rem; background: var(--ss-surface); margin-block: .8rem 1rem;
}
.ss-store [role="alert"] { border-inline-start-color: var(--ss-danger); }
.ss-alert:focus { outline: 3px solid var(--ss-focus); outline-offset: 3px; }
.ss-store [id] { scroll-margin-block-start: 1rem; }
.ss-store:has(.checkout-layout) [aria-invalid="true"] { scroll-margin-block-end: 6rem; }
.ss-alert ul { margin: 0; padding-inline-start: 1.1rem; }
.ss-alert li:only-child { list-style: none; margin-inline-start: -1.1rem; }
.ss-store form:not(.inline) { min-width: 0; }
.ss-store .ss-form, .ss-store .checkout-fields, .ss-store .product-buy { display: grid; align-content: start; gap: 1rem; }
.ss-store label { display: grid; gap: .3rem; min-width: 0; color: var(--ss-text); font-weight: 560; font-size: .9375rem; }
.ss-store :is(input,select,textarea):not([type="checkbox"]):not([type="radio"]):not([type="hidden"]) {
    min-width: 0; max-width: 100%; min-height: 2.7rem; width: 100%; padding: .5rem .7rem;
    color: var(--ss-text); background: var(--ss-surface); border: 1px solid var(--ss-control-border);
    border-radius: .4rem; font: 400 1rem/1.4 var(--ss-font);
}
.ss-store input[type="number"]:not([type="hidden"]) { width: 6rem; }
.ss-store input:is([type="checkbox"],[type="radio"]) { width: 1.12rem; height: 1.12rem; accent-color: var(--ss-accent); margin: 0 .18rem 0 0; vertical-align: middle; }
.ss-store label:has(> input[type="checkbox"]), .ss-store label:has(> input[type="radio"]) { display: flex; align-items: center; gap: .45rem; }
.ss-store textarea { resize: vertical; min-height: 5.25rem; }
.ss-store :is(input,select,textarea)[aria-invalid="true"] { border: 2px solid var(--ss-danger) !important; }
.ss-store .field-error { color: var(--ss-danger); display: block; font-size: .875rem; font-weight: 500; }
.ss-store fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
.ss-store legend { font-weight: 650; margin-bottom: .6rem; padding: 0; }
.ss-store button, .ss-store .ss-button, .ss-store .catalog-button, .ss-store .catalog-options {
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem; justify-self: start;
    min-height: 2.7rem; min-width: 0; max-width: 100%; padding: .55rem .95rem; cursor: pointer; font: 600 .94rem/1.3 var(--ss-font);
    border: 1px solid var(--ss-control-border); border-radius: .45rem; background: var(--ss-surface); color: var(--ss-text); text-decoration: none; text-align: center;
}
.ss-store button:hover, .ss-store .ss-button:hover, .ss-store .catalog-options:hover { background: var(--ss-subtle); }
.ss-store button.ss-button--primary, .ss-store a.ss-button--primary, .ss-store .catalog-button, .ss-store .catalog-purchase button, .ss-store .product-buy button,
.ss-store .checkout-fields > button, .ss-store .ss-form > button:not(.ss-button--quiet) { background: var(--ss-accent); color: var(--ss-accent-text); border-color: var(--ss-accent); }
.ss-store :is(.ss-button--primary,.catalog-button):hover, .ss-store :is(.catalog-purchase,.product-buy,.checkout-fields,.ss-form) > button:hover { filter: brightness(1.18); }
.ss-store .ss-button--quiet { border-color: transparent; background: transparent; color: var(--ss-muted); }
.ss-store button:disabled { opacity: .55; cursor: default; }
.ss-store details > summary { cursor: pointer; color: var(--ss-text); font-weight: 580; }
.ss-store details[open] > summary { margin-bottom: .75rem; }
.ss-store .ss-badge { display: inline-block; width: fit-content; padding: .2rem .55rem; background: var(--ss-subtle); color: var(--ss-text); border-radius: .35rem; font-size: .85rem; font-weight: 620; }
.ss-store .ss-total { font-size: 1.15rem; font-weight: 675; font-variant-numeric: tabular-nums; }
.ss-store address { font-style: normal; color: var(--ss-muted); }
.ss-store .catalog-filters { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; }
.ss-store form.catalog-filters { border-block: 1px solid var(--ss-border); padding-block: 1rem; }
.ss-store .catalog-filters label:first-of-type { flex: 1 1 13rem; }
.ss-store .catalog-filters > details { flex-basis: 100%; }
.ss-store .catalog-filters > details .catalog-filters { padding-top: .65rem; }
.ss-store .catalog-basic { display: flex; flex: 1 1 31rem; align-items: end; flex-wrap: wrap; gap: .75rem; min-width: 0; }
.ss-store .catalog-basic label { flex: 1 1 8rem; }
.ss-store form.catalog-toolbar { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; }
.ss-store .catalog-toolbar > :is(details, button) { justify-self: start; }
.ss-store .catalog-toolbar > details { width: 100%; }
.ss-store .catalog-toolbar > details > summary { width: fit-content; min-height: 2.7rem; padding: .48rem .8rem; border: 1px solid var(--ss-control-border); border-radius: .4rem; background: var(--ss-surface); }
.ss-store .active-filters { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .9rem; }
.ss-store .active-filters a, .ss-store .product-variants a { display: inline-flex; align-items: center; padding: .45rem .7rem; background: var(--ss-surface); border: 1px solid var(--ss-border); border-radius: .4rem; font-size: .9rem; text-decoration: none; }
.ss-store .active-filters a:hover, .ss-store .product-variants a:hover { border-color: var(--ss-accent); }
.ss-store .catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%,18rem),1fr)); gap: 1rem; margin-block: 1.5rem; }
.ss-store .catalog-product { display: flex; position: relative; flex-direction: column; align-items: stretch; gap: .45rem; padding: 1.1rem; }
.ss-store .ss-merchandising { margin-block: 2rem; }
.ss-store .catalog-product :is(h2,h3) { margin: 0; font-size: 1.18rem; }
.ss-store .catalog-product h2 a { text-decoration: none; }
.ss-store .catalog-product h2 a::after { content: ""; position: absolute; inset: 0; border-radius: var(--ss-radius); }
.ss-store .catalog-product:hover, .ss-store .catalog-product:focus-within { border-color: var(--ss-control-border); }
.ss-store .catalog-product a:focus-visible { outline: none; text-decoration: underline; }
.ss-store .catalog-product a:focus-visible::after { outline: 3px solid var(--ss-focus); outline-offset: 2px; }
.ss-store .catalog-product p { margin: 0; }
.ss-store .catalog-product .catalog-price { margin-top: auto; padding-top: .35rem; }
.ss-store .catalog-summary, .ss-store .catalog-availability { color: var(--ss-muted); }
.ss-store .catalog-availability, .ss-store .catalog-count { font-size: .9rem; }
.ss-store .catalog-count { margin: 1rem 0 -.65rem; }
.ss-store .ss-product-description img { display: block; max-width: 100%; height: auto; }
.ss-store .ss-product-description ul { list-style: disc; padding-inline-start: 1.5rem; }
.ss-store .ss-product-description ol { list-style: decimal; padding-inline-start: 1.5rem; }
.ss-store .catalog-description { width: 100%; }
.ss-store .catalog-description h2 { font-size: 1.18rem; margin-bottom: .4rem; }
.ss-store .catalog-description h2 a { text-decoration: none; } .ss-store .catalog-description h2 a:hover { text-decoration: underline; }
.ss-store .catalog-description p { margin-block: .35rem; }
.ss-store .catalog-description p:not(.catalog-price) { color: var(--ss-muted); }
.ss-store .catalog-image, .ss-store .product-gallery a { display: grid; place-items: center; background: var(--ss-subtle); border-radius: .4rem; }
.ss-store .catalog-image { margin-bottom: .45rem; }
.ss-store .catalog-image img { width: 100%; height: 13rem; object-fit: contain; }
.ss-store .catalog-price, .ss-store .product-price { font-weight: 650; font-variant-numeric: tabular-nums; }
.ss-store del { color: var(--ss-muted); font-size: .9em; font-weight: 400; }
.ss-store .catalog-product :is(form.inline,.catalog-options,.catalog-purchase) { margin-block-start: auto; align-items: end; }
.ss-store .catalog-product form { width: 100%; }
.ss-store .store-pagination { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 1rem; margin: 1.3rem 0; }
.ss-store .store-pagination a { display: inline-flex; min-height: 2.75rem; align-items: center; }
.ss-store .product-layout { display: grid; grid-template-columns: minmax(0,1fr); gap: clamp(1rem,3vw,2rem); margin-bottom: 1.5rem; }
.ss-store .product-layout:has(.product-gallery) { grid-template-columns: minmax(0,1.05fr) minmax(0,1fr); }
.ss-store .product-info { min-width: 0; max-width: 44rem; }
.ss-store .product-gallery { display: grid; align-content: start; grid-template-columns: repeat(4,minmax(0,1fr)); gap: .6rem; }
.ss-store .product-gallery img { display: block; max-width: 100%; width: 100%; height: 6rem; object-fit: contain; }
.ss-store .product-gallery a:first-child { grid-column: 1/-1; }
.ss-store .product-gallery a:first-child img { height: clamp(12rem,32vw,26rem); }
.ss-store .product-price { font-size: 1.4rem; }
.ss-store .product-variants { display: flex; flex-wrap: wrap; gap: .45rem; }
.ss-store .product-variants a[aria-current] { border-color: var(--ss-accent); color: var(--ss-accent-text); background: var(--ss-accent); }
.ss-store .product-buy-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .75rem; }
.ss-store .product-buy-actions label, .ss-store .product-buy-actions .field-error { flex-basis: 100%; }
.ss-store .product-buy-actions input[type="number"] { width: 4.75rem; max-width: 100%; }
.ss-store .product-buy { max-width: 34rem; margin-block: 1rem; }
.ss-store .product-specs { display: grid; grid-template-columns: minmax(0,max-content) minmax(0,1fr); gap: .6rem 1rem; }
.ss-store .product-specs dd { margin: 0; }
.ss-store .checkout-layout { display: grid; grid-template-columns: minmax(0,1.65fr) minmax(16rem,1fr); gap: clamp(1rem,2vw,1.75rem); }
.ss-store .checkout-summary { position: sticky; top: 1rem; align-self: start; }
.ss-store .checkout-fields :is(fieldset,[data-digital-billing]), .ss-store .address-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,14rem),1fr)); gap: .9rem; }
.ss-store .ss-saved-address { grid-column: 1/-1; display: flex; flex-wrap: wrap; align-items: end; gap: .5rem; }
.ss-store .ss-saved-address label { flex: 1; }
.ss-store .ss-address-autofill .ss-saved-address button { display: none; }
.ss-store .checkout-fields [data-digital-billing] { grid-column: 1/-1; }
.ss-store .checkout-destination > label { display: inline; }
.ss-store .checkout-destination > fieldset { margin-top: 1rem; }
.ss-store .checkout-destination > input:not(:checked) ~ fieldset { display: none; }
.ss-store .checkout-steps { display: flex; list-style: none; counter-reset: ss-step; flex-wrap: wrap; gap: .5rem 1.3rem; margin: 0 0 1.4rem; padding: 0; }
.ss-store .checkout-steps li { display: flex; align-items: center; gap: .5rem; counter-increment: ss-step; font-size: .94rem; color: var(--ss-muted); }
.ss-store .checkout-steps li::before { content: counter(ss-step); display: grid; place-items: center; width: 1.65rem; height: 1.65rem; border: 1px solid var(--ss-border); background: var(--ss-surface); border-radius: 50%; font-size: .8rem; font-weight: 650; }
.ss-store .checkout-steps [aria-current] { font-weight: 680; color: var(--ss-text); }
.ss-store .checkout-steps [aria-current]::before { background: var(--ss-accent); color: var(--ss-accent-text); border-color: var(--ss-accent); }
.ss-store .checkout-mobile-total { display: none; }
.ss-store .order-summary-line { flex-wrap: nowrap; align-items: flex-start; gap: .75rem; padding: .7rem 0; border-bottom: 1px solid var(--ss-border); }
.ss-store .order-summary-line small, .ss-store .order-breakdown small { display: block; color: var(--ss-muted); }
.ss-store .order-summary-line > div { min-width: 0; }
.ss-store :is(.order-breakdown dd,.order-summary-line > span) { font-variant-numeric: tabular-nums; }
.ss-store .order-breakdown { margin: .75rem 0; }
.ss-store .order-breakdown > div { display: flex; justify-content: space-between; gap: 1rem; padding-block: .2rem; }
.ss-store .order-breakdown dd { margin: 0; text-align: end; }
.ss-store .order-tax-details { font-size: .9rem; color: var(--ss-muted); margin: .75rem 0; }
.ss-store .order-history { display: grid; gap: .8rem; margin-block: 1rem; }
.ss-store .ss-money-detail { display: flex; flex-wrap: wrap; gap: .5rem 1.7rem; margin: .8rem 0 0; }
.ss-store .ss-money-detail dt { font-size: .85rem; }
.ss-store .ss-money-detail dd { margin: 0; font-weight: 620; font-variant-numeric: tabular-nums; }
.ss-store .ss-timeline { padding-inline-start: 1.75rem; }
.ss-store .ss-timeline li { padding: .4rem 0; }
.ss-store .ss-checkout-identity { font-size: .875rem; margin-bottom: 1.4rem; }
.ss-store .ss-form-panel { max-width: 42rem; }
@media (max-width: 45rem) {
    .ss-header-inner { align-items: flex-start; gap: .4rem; }
    .ss-header-inner:has(.ss-nav) { flex-direction: column; }
    .ss-nav { margin-inline: -.4rem; }
    .ss-nav a { padding-inline: .5rem; }
    .ss-store .checkout-layout, .ss-store .product-layout:has(.product-gallery) { grid-template-columns: minmax(0,1fr); }
    .ss-store .checkout-summary { position: static; scroll-margin-bottom: 6rem; }
    .ss-store:has(.checkout-layout) { padding-bottom: 4.5rem; }
    .ss-store .checkout-mobile-total { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; position: fixed; inset: auto 0 0; z-index: 1; padding: .7rem 1rem max(.7rem,env(safe-area-inset-bottom)); border-top: 1px solid var(--ss-border); background: var(--ss-surface); }
    .ss-store .product-gallery a:first-child img { height: 14rem; }
}
@media (max-width: 35rem) {
    .ss-store .product-specs { grid-template-columns: minmax(0,1fr); gap: 0; }
    .ss-store .product-specs dd { margin-bottom: .7rem; }
    .ss-store .catalog-filters > label { flex: 1 1 10rem; }
    .ss-store .catalog-basic { flex-basis: 100%; }
    .ss-store .catalog-basic label:first-child { flex-basis: 100%; }
}
.ss-store .checkout-codes { grid-column: 1/-1; padding-block: .65rem; border-block: 1px solid var(--ss-border); }
.ss-store .ss-radio-field { display: grid; grid-template-columns: minmax(0,1fr); gap: .45rem; }
.ss-store .order-history .row h2 { margin: 0; }
.ss-store .receipt-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: .55rem 1rem; margin-bottom: 1rem; }
.ss-store .receipt-head h1 { margin: 0; }
.ss-store .receipt-columns { display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,16rem),1fr)); gap: 1.25rem; }
.ss-store .receipt-columns h3 { margin: 0 0 .3rem; }
.ss-store .receipt-columns p { margin: 0; }
.ss-store .variant-options + .variant-options { margin-top: .75rem; }

.ss-store .ss-balance-result { display: grid; gap: .25rem; }
.ss-store .ss-recovery-lines { padding-inline-start: 1.25rem; }
.ss-store .ss-recovery-lines small { display: block; }

.ss-store [data-catalog-filters] button[hidden], .ss-store [data-catalog-error][hidden] { display: none; }
.ss-store .ss-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; padding: 0; clip-path: inset(50%); white-space: nowrap; }

.ss-store .ss-checkout-methods { display: grid; gap: .5rem; }
.ss-store .ss-checkout-method, .ss-store .ss-checkout-consent { display: flex; align-items: center; gap: .65rem; }
.ss-store .ss-checkout-method { border: 1px solid var(--ss-border); border-radius: .5rem; padding: .85rem; cursor: pointer; }
.ss-store .ss-checkout-method span { flex: 1; }
.ss-store .ss-checkout-method small { display: block; }
.ss-store .ss-checkout-method:has(:checked) { border-color: var(--ss-accent); outline: 1px solid var(--ss-accent); }
.ss-store .ss-checkout-method:focus-within { outline: 2px solid currentColor; outline-offset: 2px; }
.ss-store .ss-checkout-method input, .ss-store .ss-checkout-consent input { width: auto; flex: 0 0 auto; }
