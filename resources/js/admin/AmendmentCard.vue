<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@statamic/cms/inertia';
import { Button, Card, Input, Stack } from '@statamic/cms/ui';
import ProductLookup from './ProductLookup.vue';
import CommerceFields from './CommerceFields.vue';
import DraftAddress from './DraftAddress.vue';
const props = defineProps({ order: Object, countries: Array, csrf: String });
const open = ref(false), preview = ref(null), error = ref(''), loading = ref(false), revision = ref(0);
const form = useForm({ request_id: '', version: '', reason: '', items: [], coupon: '', gateway: '', digest: '', shipping: '', shipping_address: {}, refund_reference: '' });
const products = ref({});
const rates = ref([]), rateMessage = ref(''), fetchingRates = ref(false);
const uploading = ref(new Set());
let rowId = 0;
function uploadState(row, busy) {
    if (busy) uploading.value.add(row.rowId);
    else uploading.value.delete(row.rowId);
}
function addLine() {
    form.items.push({ rowId: ++rowId, sku: '', quantity: 1, options: {}, line_fields: {} });
}
const physical = computed(() => form.items.some(row => row.sku && !products.value[row.sku]?.digital));
function begin() {
    form.reset();
    uploading.value.clear();
    form.clearErrors();
    error.value = '';
    preview.value = null;
    products.value = { ...props.order.amendCatalog };
    const items = props.order.lines.map(line => {
        products.value[line.sku] ??= { title: line.name, digital: line.digital };
        return { rowId: ++rowId, key: line.key, sku: line.sku, quantity: line.qty,
            options: Object.fromEntries(line.options.map(option => [option.handle, option.value])),
            line_fields: { ...props.order.amendFieldValues[line.key] } };
    });
    Object.assign(form, { request_id: props.order.amendRequestId, version: props.order.amendVersion, coupon: props.order.coupon,
        shipping: props.order.shippingKey || '', shipping_address: { ...(props.order.shippingAddress || props.order.address) }, items });
    open.value = true;
}
function choose(row, product) {
    if (row.sku !== product.sku) Object.assign(row, { key: null, sku: product.sku, options: {}, line_fields: {} });
    products.value[product.sku] = product;
}
function payload(data) {
    return { ...data, items: data.items.map(({ rowId, ...item }) => item),
        ...(physical.value ? {} : { shipping: null, shipping_address: undefined }) };
}
async function request(url, signal) {
    const res = await fetch(url, { method: 'POST', signal, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': props.csrf },
        body: JSON.stringify(payload(form.data())) });
    const body = await res.json();
    if (!res.ok) throw new Error(Object.values(body.errors || {}).flat().join(' ') || body.message);
    return body;
}
watch(() => [open.value, form.items, form.coupon, form.shipping_address], (_next, _previous, cleanup) => {
    rates.value = [];
    rateMessage.value = '';
    fetchingRates.value = false;
    if (!open.value || !physical.value || !form.shipping_address.country) return;
    if (form.items.some(row => !row.sku || Object.entries(products.value[row.sku]?.option_fields || {})
        .some(([handle, field]) => field.required && !row.options[handle]?.trim()))) {
        rateMessage.value = __('Choose a product and complete its options to see delivery services.');
        return;
    }
    const controller = new AbortController();
    fetchingRates.value = true;
    const timer = setTimeout(async () => {
        try {
            const body = await request(props.order.amendDelivery, controller.signal);
            if (controller.signal.aborted) return;
            rates.value = body.rates;
            if (!rates.value.some(rate => rate.id === form.shipping)) form.shipping = rates.value[0]?.id || '';
        } catch (e) {
            if (!controller.signal.aborted) rateMessage.value = e.message;
        } finally {
            if (!controller.signal.aborted) fetchingRates.value = false;
        }
    }, 250);
    cleanup(() => { clearTimeout(timer); controller.abort(); });
}, { deep: true });
watch(() => [open.value, form.items, form.coupon, form.shipping, form.shipping_address], () => {
    preview.value = null;
    form.digest = '';
    error.value = '';
    revision.value++;
}, { deep: true });
async function quote() {
    const at = revision.value;
    loading.value = true;
    error.value = '';
    try {
        const body = await request(props.order.amendPreview);
        if (at === revision.value) {
            preview.value = body;
            form.digest = body.digest;
            if (!body.gateways.includes(form.gateway)) form.gateway = body.gateways[0] || '';
        }
    } catch (e) { if (at === revision.value) error.value = e.message; }
    finally { loading.value = false; }
}
function submit() {
    if (preview.value) form.transform(payload).post(props.order.amendUrl, { onSuccess: () => open.value = false });
    else quote();
}
</script>
<template>
    <Card v-if="order.canAmend || order.amendments?.length" class="ss-body"><h2>{{ __('Order changes') }}</h2>
        <p v-for="change in order.amendments" :key="change.id">{{ change.reason }} · {{ change.status }} · {{ change.balance }}
            <a v-if="change.link" :href="change.link">{{ __('Payment link') }}</a>
            <Button v-if="change.emailUrl" size="sm" :text="__('Email payment link')" @click="router.post(change.emailUrl)" />
            <Button v-if="change.retryUrl" size="sm" :text="__('Retry refund')" @click="router.post(change.retryUrl)" />
        </p>
        <Button v-if="order.canAmend" :text="__('Change order items')" @click="begin" />
        <Stack v-model:open="open" size="half" :title="__('Change order items')"><form id="ss-amend-order" class="ss-form" @submit.prevent="submit">
            <p>{{ __('Review current product prices, promotions, tax and delivery before applying the change.') }}</p>
            <fieldset v-for="(item,index) in form.items" :key="item.rowId" class="ss-form">
                <legend>{{ __('Item') }} {{ index + 1 }}</legend>
                <div class="ss-field-grid">
                    <div><ProductLookup :id="'amend-product-' + item.rowId" :url="order.amendProducts" :sku="item.sku" :product="products[item.sku]" @select="choose(item, $event)" /></div>
                    <label>{{ __('Quantity') }}<Input v-model="item.quantity" type="number" min="1" max="999" required /></label>
                    <Button :text="__('Remove')" type="button" @click="form.items.splice(index, 1)" />
                </div>
                <div v-if="Object.keys(products[item.sku]?.option_fields || {}).length" class="ss-field-grid">
                    <label v-for="(field, handle) in products[item.sku].option_fields" :key="handle">{{ field.label }}<span v-if="field.required"> *</span>
                        <Input v-model="item.options[handle]" :required="field.required" :maxlength="field.max_length" />
                    </label>
                </div>
                <CommerceFields v-if="item.sku" scope="line" :fields="order.amendLineFields" v-model="item.line_fields" :upload-url="order.amendArtwork" :csrf complete @uploading="uploadState(item, $event)" />
            </fieldset>
            <Button type="button" :text="__('Add item')" :disabled="form.items.length >= 100"
                @click="addLine" />
            <label>{{ __('Reason') }}<Input v-model="form.reason" required /></label>
            <label v-if="order.coupon">{{ __('Promotion') }}<select v-model="form.coupon"><option :value="order.coupon">{{ order.coupon }}</option><option value="">{{ __('Remove coupon') }}</option></select></label>
            <fieldset v-if="physical" class="ss-form">
                <legend>{{ __('Delivery') }}</legend>
                <DraftAddress v-model="form.shipping_address" prefix="shipping_address" :countries :errors="form.errors" delivery recipient />
                <div v-if="rates.length">
                    <label for="amend-delivery-method">{{ __('Delivery method') }}</label>
                    <select id="amend-delivery-method" v-model="form.shipping" required><option v-for="rate in rates" :key="rate.id" :value="rate.id">{{ rate.label }} · {{ rate.price }}</option></select>
                </div>
                <p v-else role="status">{{ fetchingRates ? __('Checking delivery services…') : rateMessage || __('No delivery service is available for these items and destination.') }}</p>
            </fieldset>
            <template v-if="preview"><p v-for="(charge, handle) in preview.charges" :key="handle">{{ charge.label }} · {{ charge.amount }}</p><p>{{ __('New total') }} {{ preview.total }} {{ __('· Tax') }} {{ preview.tax }} {{ __('· Delivery') }} {{ preview.shipping }}</p>
                <p>{{ preview.difference > 0 ? __('To collect') : preview.difference < 0 ? __('To refund') : __('Balance due') }} {{ preview.balance }}</p>
                <label v-if="preview.difference > 0">{{ __('Payment method') }}<select v-model="form.gateway"><option v-for="g in preview.gateways" :key="g">{{ g }}</option></select></label>
                <label v-if="preview.difference < 0">{{ __('Offline refund reference (only if already refunded externally)') }}<Input v-model="form.refund_reference" /></label>
            </template>
            <p v-if="preview?.difference > 0 && !preview.gateways.length" role="status">{{ __('No eligible payment method for this balance.') }}</p>
            <p v-if="error" role="alert">{{ error }}</p><p v-for="msg in form.errors" role="alert">{{ msg }}</p>
        </form>
        <template #footer-end><Button :text="__('Cancel')" @click="open = false" />
            <Button variant="primary" type="submit" form="ss-amend-order" :disabled="uploading.size > 0 || loading || fetchingRates || form.processing || !form.items.length || form.items.some(row => !row.sku) || (physical && !rates.some(rate => rate.id === form.shipping)) || (preview?.difference > 0 && !form.gateway)" :text="preview ? __('Confirm changes') : __('Review changes')" /></template>
        </Stack>
    </Card>
</template>
