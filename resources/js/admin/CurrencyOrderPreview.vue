<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Alert, Button, Field, Input } from '@statamic/cms/ui';
import StoreChoice from './StoreChoice.vue';

const props = defineProps({ site: String, endpoint: String, currency: String, firstSku: String, countries: Object, defaultCountry: String });
const sku = ref('');
const quantity = ref(1);
const country = ref('');
const region = ref('');
const postcode = ref('');
const service = ref('');
const quote = ref(null);
const error = ref('');
const busy = ref(false);
const countries = computed(() => Object.entries(props.countries || {}).map(([value, name]) => ({ value, label: name + ' (' + value + ')' })));
const services = computed(() => Object.entries(quote.value?.services || {}).map(([value, label]) => ({ value, label })));
let pending;
function invalidate() {
    pending?.abort();
    busy.value = false;
    quote.value = null;
    service.value = '';
    error.value = '';
}
watch(() => [props.site, props.currency, props.firstSku], () => {
    invalidate();
    sku.value = props.firstSku || '';
    country.value = props.defaultCountry || Object.keys(props.countries || {})[0] || '';
}, { immediate: true });
watch([sku, quantity, country, region, postcode], invalidate);
onBeforeUnmount(() => pending?.abort());
async function calculate(newService = '') {
    pending?.abort();
    const current = pending = new AbortController();
    error.value = '';
    busy.value = true;
    try {
        const url = new URL(props.endpoint, window.location.href);
        for (const [key, value] of Object.entries({ site: props.site, target: props.currency, sku: sku.value, quantity: quantity.value,
            country: country.value, region: region.value, postal_code: postcode.value, service: newService })) {
            if (value !== '') url.searchParams.set(key, value);
        }
        const result = await fetch(url, { headers: { Accept: 'application/json' }, signal: current.signal });
        const data = await result.json();
        if (!result.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
        if (current !== pending) return;
        quote.value = data;
        service.value = data.service || '';
    } catch (failure) {
        if (failure.name !== 'AbortError' && current === pending) {
            quote.value = null;
            error.value = failure.message || __('Unable to preview this order.');
        }
    } finally {
        if (current === pending) busy.value = false;
    }
}
</script>
<template>
    <details>
        <summary>{{ __('Preview a sample order') }}</summary>
        <div class="ss-form">
            <Field :label="__('SKU')" id="ss-sample-sku"><Input id="ss-sample-sku" v-model="sku" /></Field>
            <Field :label="__('Quantity')" id="ss-sample-qty"><Input id="ss-sample-qty" v-model="quantity" type="number" min="1" max="999" /></Field>
            <Field :label="__('Country')" id="ss-sample-country"><StoreChoice id="ss-sample-country" v-model="country" :options="countries" /></Field>
            <Field :label="__('Region')" id="ss-sample-region"><Input id="ss-sample-region" v-model="region" /></Field>
            <Field :label="__('Postal code')" id="ss-sample-postcode"><Input id="ss-sample-postcode" v-model="postcode" /></Field>
            <div><Button type="button" :text="__('Calculate sample order')" :disabled="busy || !sku || !quantity || !country" @click="calculate()" /></div>
            <Alert v-if="error" variant="error" live>{{ error }}</Alert>
            <div v-if="quote" aria-live="polite" class="ss-form">
                <p>{{ quote.quantity }} × {{ quote.title }} · {{ quote.price }}</p>
                <Field v-if="services.length" :label="__('Delivery service')" id="ss-sample-service">
                    <StoreChoice id="ss-sample-service" :model-value="service" :options="services" :read-only="busy" @update:model-value="calculate" />
                </Field>
                <p v-else-if="quote.physical">{{ __('No delivery service quoted for this destination.') }}</p>
                <dl class="ss-settings-status">
                    <div><dt>{{ __('Merchandise subtotal') }}</dt><dd>{{ quote.subtotal }}</dd></div>
                    <div><dt>{{ __('Discount') }}</dt><dd>{{ quote.discount }}</dd></div>
                    <div v-if="quote.shipping"><dt>{{ __('Shipping') }}</dt><dd>{{ quote.shipping }}</dd></div>
                    <div v-for="(charge,index) in quote.charges" :key="index"><dt>{{ charge.label }}</dt><dd>{{ charge.amount }}</dd></div>
                    <div><dt>{{ __('Tax') }}</dt><dd>{{ quote.tax }}</dd></div>
                    <div><dt>{{ __('Estimated total') }}</dt><dd>{{ quote.total }}</dd></div>
                    <div><dt>{{ __('Payment methods') }}</dt><dd>{{ quote.free ? __('Free order') : quote.methods.map(method => method.replaceAll('_', ' ')).join(', ') || __('None available') }}</dd></div>
                </dl>
                <p v-for="issue in quote.issues" :key="issue">{{ issue }}</p>
            </div>
        </div>
    </details>
</template>
