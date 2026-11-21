<script setup>
import CommerceFields from './CommerceFields.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@statamic/cms/inertia';
import { Header, Card, Input, Button, Icon, Modal, Field, Select, Textarea } from '@statamic/cms/ui';
import DraftAddress from './DraftAddress.vue';
import CustomerLookup from './CustomerLookup.vue';
import ProductLookup from './ProductLookup.vue';
import StoreChoice from './StoreChoice.vue';

const props = defineProps({
    customersUrl: String, currencyChange: Object, rebuildUrl: String,
    creationId: String,
    draft: Object,
    site: String,
    sites: Object,
    countries: Object,
    currency: String,
    coupons: Array,
    products: Array,
    catalogUrl: String,
    saveUrl: String,
    csrfToken: String, orderFields: Object, lineFields: Object, uploadUrl: String,
    quoteUrl: String,
    newUrl: String,
    listUrl: String,
    cancelUrl: String,
});
let sequence = 0;
const line = (item = {}) => ({
    row: ++sequence,
    sku: item.sku || '', service: item.service || '', custom: item.custom || !!item.service, unit_price: item.unit_price ?? '',
    qty: item.qty ?? 1,
    options: { ...item.options }, line_fields: { ...item.line_fields },
});
function addressFields(address = {}) {
    return Object.fromEntries(['name', 'company', 'phone', 'street', 'street2', 'city', 'region', 'postal_code', 'country'].map(key => [key, address?.[key] || '']));
}
function localTime(value) {
    if (!value) return '';
    const date = new Date(value);
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
}
function fields() {
    const input = props.draft?.input || {};
    return {
        creation_id: props.creationId,
        id: props.draft?.id || null,
        revision: props.draft?.revision || 0,
        reserve_until: localTime(props.draft?.reserveUntil),
        site: props.site,
        email: input.email || '',
        order_fields: { ...input.order_fields },
        customer_id: input.customer_id || null,
        separate_shipping: !!input.shipping_address,
        shipping_address: addressFields(input.shipping_address),
        shipping_instructions: input.shipping_instructions || '',
        pay_on_delivery: !!(input.custom_shipping?.pay_on_delivery || input.pending_delivery?.pay_on_delivery),
        custom_delivery: !!(input.custom_shipping || input.pending_delivery),
        delivery_label: input.custom_shipping?.label || input.pending_delivery?.delivery_label || '',
        delivery_charge: props.draft?.customRate || input.pending_delivery?.delivery_charge || '',
        address: addressFields(input.address),
        discount: input.discount || '',
        shipping: input.shipping || '',
        items: props.draft?.items.map(line) || [line()],
    };
}
const form = useForm(fields());
const errorsSummary = ref(null);
let continuing = false;
let stopNavigationGuard;
function unload(event) {
    if (form.isDirty && !continuing) {
        event.preventDefault();
        event.returnValue = '';
    }
}
onMounted(() => {
    window.addEventListener('beforeunload', unload);
    stopNavigationGuard = router.on('before', () => {
        if (form.isDirty && !continuing && !window.confirm(__('Discard your unsaved changes?'))) return false;
    });
});
function submitDraft(url) {
    continuing = true;
    form.post(url, {
        preserveScroll: true,
        onError: () => nextTick(() => {
            const invalid = document.querySelector('.ss-draft [aria-invalid="true"]');
            (invalid || errorsSummary.value)?.focus();
        }),
        onFinish: () => { continuing = false; },
    });
}
const countryChoices = computed(() =>
    Object.entries(props.countries).map(([value, label]) => ({ value, label })),
);
function selectCustomer(customer) {
    form.customer_id = customer.customer_id;
    form.email = customer.email;
    form.address = addressFields(customer.address);
    form.shipping_address = addressFields(customer.shipping_address);
    form.separate_shipping = !!customer.shipping_address;
}
function enterGuest() {
    selectCustomer({ customer_id: null, email: '', address: {}, shipping_address: null });
}
const catalog = ref(new Map());
watch(() => [props.site, props.products], () => {
    catalog.value = new Map(props.products.map(product => [product.sku, product]));
}, { immediate: true });
function addService() {
    const blank = form.items.find(item => !item.sku && !item.custom);
    if (blank) blank.custom = true;
    else form.items.push(line({ custom: true }));
}
function selectProduct(item, product) {
    catalog.value.set(product.sku, product);
    if (item.sku !== product.sku) {
        item.sku = product.sku;
        item.options = {};
    }
}
const coupons = computed(() => props.coupons.map((value) => ({ value, label: value })));
const physical = computed(() => form.items.some((item) => catalog.value.get(item.sku)?.digital === false));
const quote = ref(null);
const methods = ref({});
const message = ref('');
const checking = ref(false);
const copied = ref(false);
const cancelOpen = ref(false);
const newSite = ref('');
let currentRequest;
let delay;
const product = (item) => catalog.value.get(item.sku);
const country = computed(() => props.countries[form.address.country] || form.address.country);
const ready = computed(
    () =>
        quote.value?.ready && !message.value,
);
const hasItems = computed(() => form.items.length && form.items.every((item) => (item.custom ? item.service.trim() && item.unit_price !== '' : item.sku) && item.qty > 0));
function payload() {
    const data = form.data();
    data.reserve_until = form.reserve_until ? new Date(form.reserve_until).toISOString() : null;
    data.address = { ...form.address };
    if ((physical.value || !hasItems.value) && data.separate_shipping) {
        data.shipping_address = { ...form.shipping_address };
        if (!data.shipping_address.country) delete data.shipping_address.country;
    } else delete data.shipping_address;
    if ((!physical.value && hasItems.value) || !data.custom_delivery) {
        data.custom_delivery = false;
        delete data.delivery_label;
        delete data.delivery_charge;
    }
    delete data.separate_shipping;
    data.items = form.items.map(({ sku, service, custom, unit_price, qty, options, line_fields }) => ({ sku: custom ? null : sku, service: custom ? service : null, unit_price: unit_price === '' ? null : String(unit_price), qty, options: { ...options }, line_fields: { ...line_fields } }));
    if (!data.address.country) delete data.address.country;
    return data;
}
function save(intent = 'save') {
    form.transform(() => {
        const data = payload();
        return { ...data, intent, items: data.items.filter((item) => item.sku || item.service) };
    });
    submitDraft(props.saveUrl);
}
watch(
    () => [props.draft?.id, props.draft?.revision, props.site],
    () => {
        Object.assign(form, fields());
        form.defaults();
        copied.value = false;
    },
);
watch(
    () => payload(),
    (_, __, cleanup) => {
        quote.value = null;
        message.value = '';
        methods.value = {};
        copied.value = false;
        checking.value = Boolean(!props.draft?.cancelled && !props.currencyChange && hasItems.value);
        form.clearErrors();
        if (!checking.value) return;
        const controller = new AbortController();
        currentRequest = controller;
        delay = setTimeout(() => preview(controller), 280);
        cleanup(() => {
            clearTimeout(delay);
            controller.abort();
        });
    },
    { deep: true, immediate: true },
);
onBeforeUnmount(() => {
    clearTimeout(delay);
    currentRequest?.abort();
    window.removeEventListener('beforeunload', unload);
    stopNavigationGuard?.();
});
async function preview(controller) {
    try {
        const response = await fetch(props.quoteUrl, {
            method: 'POST',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
            },
            body: JSON.stringify(payload()),
        });
        const data = await response.json();
        if (controller.signal.aborted) return;
        if (!response.ok)
            throw new Error(
                Object.values(data.errors || {}).flat()[0] ||
                    (response.status < 500 && data.message) ||
                    __('Could not calculate pricing.'),
            );
        methods.value = data.methods;
        const handles = Object.keys(data.methods);
        if (
            physical.value &&
            !form.custom_delivery &&
            !handles.includes(form.shipping) &&
            (form.shipping || handles.length === 1)
        ) {
            const pristine = !form.isDirty;
            form.shipping = handles.length === 1 ? handles[0] : '';
            if (pristine) form.defaults('shipping', form.shipping);
            return;
        }
        quote.value = data;
        const shortages = Object.entries(data.stock_shortages);
        if (shortages.length)
            message.value = shortages
                .map(([sku, n]) => __(':sku has :count available.', { sku: catalog.value.get(sku)?.title || sku, count: n }))
                .join(' ');
    } catch (error) {
        if (!controller.signal.aborted)
            message.value =
                error instanceof SyntaxError ? __('Pricing could not be loaded. Try again.') : error.message;
    } finally {
        if (!controller.signal.aborted) checking.value = false;
    }
}
function retry() {
    currentRequest?.abort();
    currentRequest = new AbortController();
    checking.value = true;
    message.value = '';
    preview(currentRequest);
}
async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.draft.link);
        copied.value = true;
    } catch {
        const input = document.getElementById('draft-link');
        input.focus();
        input.select();
    }
}
function changeSite(site) {
    newSite.value = site;
    if (!form.isDirty) visitSite();
}
function visitSite() {
    continuing = true;
    router.visit(props.newUrl, { data: { site: newSite.value }, onFinish: () => { continuing = false; } });
    newSite.value = '';
}
</script>
<template>
    <Head :title="draft ? __('Draft order') : __('Create draft order')" />
    <div class="ss-page">
        <Link class="ss-back" :href="listUrl"><Icon name="arrow-left" /> {{ __('Draft orders') }}</Link>
        <Header :title="draft ? __('Draft order') : __('Create draft order')" />
        <Card v-if="draft?.cancelled" inset
            ><div class="ss-empty">
                <h2>{{ __('Cancelled draft') }}</h2>
                <p>{{ __('The customer payment link is closed.') }}</p>
                <Link :href="listUrl">{{ __('Back to drafts') }}</Link>
            </div></Card
        >
        <Card v-else-if="currencyChange" class="ss-form">
            <h2>{{ __('Review the currency change') }}</h2>
            <p>{{ __('This draft is priced in :from. Rebuild it in :to to continue editing.', { from: currencyChange.from, to: currencyChange.to }) }}</p>
            <ul><li v-for="(line,i) in currencyChange.lines" :key="i">{{ line.qty }} × {{ line.title }} · {{ line.price }}</li>
                <li v-for="(line,i) in currencyChange.removed" :key="'removed'+i">{{ __('Removed') }} · {{ line.qty }} × {{ line.title }}</li></ul>
            <p v-if="currencyChange.customPrices.length">{{ __('Catalog prices replace manual prices for these items') }} · {{ currencyChange.customPrices.join(', ') }}</p>
            <p>{{ __('Merchandise subtotal') }} · {{ currencyChange.subtotal }}</p>
            <p>{{ __('Rebuilding closes the previous payment link and releases reservations. Review delivery, discounts, custom prices and any bespoke services before sending a new link.') }}</p>
            <div><Button :text="__('Rebuild draft')" variant="primary" @click="router.post(rebuildUrl, { revision: draft.revision, digest: currencyChange.digest })" />
                <Button :text="__('Cancel draft')" @click="cancelOpen = true" /></div>
        </Card>
        <form v-else class="ss-record-layout ss-draft" novalidate @submit.prevent="save()">
            <div class="ss-stack">
                <Card inset>
                    <div class="ss-section-title">
                        <h2><Icon name="user-avatar" />{{ __('Customer') }}</h2>
                        <CustomerLookup :url="customersUrl" :site @select="selectCustomer" />
                    </div>
                    <div class="ss-body ss-form">
                        <div v-if="form.customer_id" class="ss-record ss-summary-line">
                            <div>
                                <strong>{{ __('Registered customer') }}</strong><small>{{ form.email }}</small>
                            </div>
                            <Button type="button" size="sm" :text="__('Use guest details')" @click="enterGuest" />
                        </div>
                        <p v-else class="ss-field-help">
                            {{ __('Select an existing customer or enter guest details below.') }}
                        </p>
                        <Field v-if="Object.keys(sites).length > 1" id="draft-site" :label="__('Store site')" :error="form.errors.site">
                            <Select id="draft-site" :model-value="site" :disabled="!!draft" :options="Object.entries(sites).map(([value, label]) => ({ value, label }))"
                                option-label="label" option-value="value" @update:model-value="changeSite" />
                        </Field>
                        <div class="ss-field-grid">
                            <label
                                >{{ __('Email address') }}<Input
                                    v-model="form.email"
                                    :read-only="!!form.customer_id"
                                    type="email"
                                    required
                                    autocomplete="email"
                                    placeholder="customer@example.com"
                            /></label>
                            <label
                                >{{ __('Full name') }}<Input
                                    v-model="form.address.name"
                                    autocomplete="name"
                                    maxlength="150"
                            /></label>
                        </div>
                    </div>
                </Card>
                <Card inset>
                    <h2 class="ss-section-title"><Icon name="shopping-cart" />{{ __('Items') }}</h2>
                    <div class="ss-body ss-stack">
                        <CommerceFields scope="order" :fields="orderFields" v-model="form.order_fields" :upload-url :csrf="csrfToken" />
                        <fieldset v-for="(item, index) in form.items" :key="item.row" class="ss-item">
                            <legend class="sr-only">{{ __('Item') }} {{ index + 1 }}</legend>
                            <div class="ss-item-line">
                                <label v-if="item.custom" class="ss-control ss-service-name">{{ __('Service description') }}<Input v-model="item.service" maxlength="150" /></label>
                                <Field v-else
                                    class="ss-control"
                                    :id="'item-' + item.row"
                                    :label="__('Product or variant')"
                                    :error="form.errors['items.' + index + '.sku']"
                                >
                                    <ProductLookup :id="'item-' + item.row" :url="catalogUrl" :sku="item.sku"
                                        :product="product(item)" @select="selectProduct(item, $event)"
                                /></Field>
                                <label
                                    >{{ __('Quantity') }}<Input
                                        v-model="item.qty"
                                        type="number"
                                        required
                                        min="1"
                                        max="999"
                                /></label>
                                <Button
                                    v-if="form.items.length > 1"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    :aria-label="__('Remove item ') + (index + 1)"
                                    @click="form.items.splice(index, 1)"
                                />
                            </div>
                            <label>{{ item.custom ? __('Unit price') : __('Custom unit price (optional)') }} · {{ currency }}
                                <Input v-model="item.unit_price" inputmode="decimal" />
                            </label>
                            <CommerceFields scope="line" :fields="lineFields" v-model="item.line_fields" :upload-url :csrf="csrfToken" />
                            <div
                                v-if="Object.keys(product(item)?.option_fields || {}).length"
                                class="ss-field-grid ss-item-options"
                            >
                                <label v-for="(field, key) in product(item).option_fields" :key="key"
                                    >{{ field.label }}
                                    <Input
                                        v-model="item.options[key]"
                                        :required="field.required"
                                        :maxlength="field.max_length"
                                /></label>
                            </div>
                        </fieldset>
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                icon="plus"
                                :text="__('Add item')"
                                :disabled="form.items.length >= 100"
                                @click="form.items.push(line())"
                            />
                            <Button type="button" size="sm" :text="__('Add service')" :disabled="form.items.length >= 100" @click="addService" />
                        </div>
                        <label>{{ __('Reserve stock until') }}
                            <Input type="datetime-local" v-model="form.reserve_until" />
                            <small>{{ __('Optional. Holds tracked items for this draft until the selected local time.') }}</small>
                        </label>
                    </div>
                </Card>
                <Card inset>
                    <h2 class="ss-section-title"><Icon name="user-avatar" />{{ __('Billing address') }}</h2>
                    <DraftAddress :delivery="physical && !form.separate_shipping"
                        v-model="form.address"
                        prefix="address"
                        :countries="countryChoices"
                        :errors="form.errors"
                        :required-fields="quote?.billing_fields || []"
                    />
                </Card>
                <Card v-if="physical || !hasItems" inset>
                    <h2 class="ss-section-title"><Icon name="package-box-crate" />{{ __('Shipping address') }}</h2>
                    <div class="ss-body ss-form">
                        <label
                            ><input
                                type="checkbox"
                                :checked="!form.separate_shipping"
                                @change="form.separate_shipping = !$event.target.checked"
                            />
                            {{ __('Same as billing address') }}</label
                        >
                    </div>
                    <DraftAddress
                        v-if="form.separate_shipping"
                        v-model="form.shipping_address"
                        prefix="shipping_address"
                        recipient
                        :countries="countryChoices"
                        :errors="form.errors"
                        delivery :required-fields="quote?.missing_shipping_fields || []"
                    />
                    <div class="ss-body ss-form">
                        <p v-if="!hasItems" class="ss-field-help">
                            {{ __('Add a physical item to see delivery methods and prices for this address.') }}
                        </p>
                        <p v-else-if="!form.separate_shipping" class="ss-field-help">
                            {{
                                [form.address.name, form.address.street, form.address.city, country]
                                    .filter(Boolean)
                                    .join(', ')
                            }}
                        </p>
                        <label v-if="physical"
                            ><span
                                ><input type="checkbox" v-model="form.custom_delivery" /> {{ __('Set a custom delivery rate') }}</span
                            ></label
                        >
                        <div v-if="physical && form.custom_delivery" class="ss-field-grid">
                            <label
                                >{{ __('Delivery service') }}<Input
                                    v-model="form.delivery_label"
                                    required
                                    maxlength="120" /></label
                            ><label
                                >{{ __('Delivery charge (') }}{{ currency }})<Input
                                    v-model="form.delivery_charge"
                                    required
                                    :placeholder="__('0.00')"
                            /></label>
                        </div>
                        <label v-if="physical && form.custom_delivery"><input type="checkbox" v-model="form.pay_on_delivery" /> {{ __('This service collects payment on delivery') }}</label>
                        <Field v-if="physical && !form.custom_delivery" id="draft-shipping" :label="__('Delivery method')" :error="form.errors.shipping">
                            <Select id="draft-shipping" v-model="form.shipping" :disabled="!Object.keys(methods).length"
                                :placeholder="!(form.separate_shipping ? form.shipping_address.country : country) ? __('Choose a country first') : checking ? __('Checking delivery…') : Object.keys(methods).length ? __('Choose delivery') : __('No delivery methods available')"
                                :options="Object.entries(methods).map(([value, label]) => ({ value, label }))" option-label="label" option-value="value" />
                        </Field>
                        <label
                            >{{ __('Delivery instructions') }}<Textarea
                                v-model="form.shipping_instructions"
                                rows="2"
                                maxlength="500"
                                :placeholder="__('For example, use the side entrance')"
                            />
                        </label>
                    </div>
                </Card>
                <Card inset>
                    <h2 class="ss-section-title"><Icon name="money-cashier-price-tag" />{{ __('Pricing') }}</h2>
                    <div class="ss-body ss-form">
                        <Field id="draft-discount" :label="__('Discount code')" :error="form.errors.discount">
                            <StoreChoice
                                id="draft-discount"
                                v-model="form.discount"
                                :options="coupons"
                                clearable
                                :placeholder="__('No coupon')"
                            />
                            <small class="ss-field-help"
                                >{{ __('Eligible automatic offers apply when no coupon is selected.') }}</small
                            >
                        </Field>
                    </div>
                </Card>
            </div>
            <aside class="ss-stack ss-summary">
                <Card inset>
                    <h2 class="ss-section-title">{{ __('Order summary') }}</h2>
                    <div class="ss-body ss-stack">
                        <div v-if="form.email || form.address.name" class="ss-record">
                            <strong>{{ form.address.name || form.email }}</strong
                            ><small v-if="form.address.name">{{ form.email }}</small>
                        </div>
                        <ul v-if="quote?.lines.length" class="ss-summary-items">
                            <li v-for="(item, i) in quote.lines" :key="i">
                                <span>{{ item.qty }} × {{ item.name }}</span
                                ><strong>{{ item.amount }}</strong>
                            </li>
                        </ul>
                        <p v-if="!hasItems" class="ss-field-help">{{ __('Choose a product to see pricing.') }}</p>
                        <dl v-if="quote" class="ss-facts">
                            <div>
                                <dt>{{ __('Subtotal') }}</dt>
                                <dd>{{ quote.subtotal }}</dd>
                            </div>
                            <div v-for="offer in quote.promotions" :key="offer.id">
                                <dt>{{ offer.label }}</dt>
                                <dd>{{ offer.free_shipping ? __('Free shipping') : '−' + offer.formatted }}</dd>
                            </div>
                            <div v-for="(charge, handle) in quote.charges" :key="handle">
                                <dt>{{ charge.label }}</dt><dd>{{ charge.amount }}</dd>
                            </div>
                            <div v-if="physical">
                                <dt>
                                    {{ __('Delivery') }}<small v-if="quote.delivery_label">{{
                                        quote.delivery_label
                                    }}</small>
                                </dt>
                                <dd>{{ quote.shipping || __('Select delivery') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Tax') }}</dt>
                                <dd>
                                    {{ quote.missing_billing_fields.length || quote.missing_shipping_fields.length ? __('Awaiting address') : quote.tax }}
                                </dd>
                            </div>
                            <template v-if="quote.shipping_tax">
                                <div><dt>{{ __('Merchandise tax') }}</dt><dd>{{ quote.merchandise_tax }}</dd></div>
                                <div><dt>{{ __('Delivery tax') }}</dt><dd>{{ quote.shipping_tax }}</dd></div>
                            </template>
                        </dl>
                        <div class="ss-grand-total" aria-live="polite">
                            <strong
                                >{{ __('Total') }} <small>{{ currency }}</small></strong
                            ><strong>{{ checking ? __('Updating…') : ready ? quote.total : '—' }}</strong>
                        </div>
                        <p v-if="quote && !quote.payment_ready" class="ss-field-help">{{ __('No eligible payment method for this order.') }}</p>
                        <p v-if="quote?.missing_billing_fields.length" class="ss-field-help">
                            {{ __('Enter') }}
                            {{
                                quote.missing_billing_fields.map((key) => key.replace('_', ' ')).join(' and ')
                            }}
                            {{ __('to calculate tax.') }}
                        </p>
                        <div v-if="message" class="ss-notice" role="alert">
                            {{ message }} <button type="button" @click="retry">{{ __('Retry') }}</button>
                        </div>
                        <div v-if="Object.keys(form.errors).length" ref="errorsSummary" tabindex="-1" role="alert">
                            <ul><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul>
                        </div>
                        <Button
                            type="submit"
                            variant="primary"
                            class="ss-save-desktop" :text="__('Save draft')"
                            :disabled="form.processing"
                        />
                    </div>
                </Card>
                <Card inset
                    ><h2 class="ss-section-title">{{ __('Customer payment link') }}</h2>
                    <div class="ss-body ss-stack">
                        <Button type="button" :text="__('Send payment link')" :disabled="form.processing || !ready || !form.email" @click="save('send')" />
                        <template v-if="draft?.link"
                            ><p class="ss-field-help">
                                {{ draft.sentAt ? __('Payment link emailed.') : __('Payment link ready.') }} {{ __('Valid for seven days. Saving changes closes this link.') }}
                            </p>
                            <Input
                                id="draft-link"
                                :aria-label="__('Customer payment link')"
                                :model-value="draft.link"
                                read-only
                                @focus="$event.target.select()"
                            />
                            <div class="ss-link-actions">
                                <Button
                                    type="button"
                                    size="sm"
                                    :text="copied ? __('Copied') : __('Copy link')"
                                    @click="copyLink"
                                /><a :href="draft.link" target="_blank" rel="noreferrer"
                                    >{{ __('Preview checkout ↗') }}</a
                                >
                            </div>
                        </template>
                        <p v-else class="ss-field-help">
                            {{ __('Complete the customer and delivery details to send a payment link. Stock is reserved when the customer places the order.') }}
                        </p>
                            <button v-if="draft" type="button" class="ss-text-button" @click="cancelOpen = true">
                                {{ __('Cancel draft') }}
                            </button>

                    </div></Card
                >
            </aside>
            <div class="ss-mobile-save"><strong>{{ ready ? __('Total') : __('Estimated total') }} · {{ quote?.total ?? '—' }}</strong>
                <Button type="submit" variant="primary" :text="__('Save draft')" :disabled="form.processing" />
            </div>
        </form>
    </div>
    <Modal v-model:open="cancelOpen" :title="__('Cancel this draft?')"
        ><p>{{ __('The customer’s payment link will close.') }}</p>
        <div class="ss-dialog-actions">
            <Button :text="__('Keep draft')" @click="cancelOpen = false" /><Button
                variant="danger"
                :text="__('Cancel draft')"
                :disabled="form.processing"
                @click="submitDraft(cancelUrl)"
            /></div
    ></Modal>
    <Modal :open="!!newSite" :title="__('Switch store site?')" @update:open="$event || (newSite = '')"
        ><p>{{ __('Switching sites starts a new draft and clears these unsaved changes.') }}</p>
        <div class="ss-dialog-actions">
            <Button :text="__('Keep editing')" @click="newSite = ''" /><Button
                :text="__('Switch site')"
                variant="primary"
                @click="visitSite"
            /></div
    ></Modal>
</template>
