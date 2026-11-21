<script setup>
import { ref, watch } from 'vue';

const props = defineProps({ url: String, savedNames: Object });
const skus = defineModel('skus', { type: Array });
const products = defineModel('products', { type: Array });
const search = ref('');
const results = ref([]);
const names = ref({});
const status = ref('');
watch(search, (value, _, cleanup) => {
    results.value = [];
    status.value = '';
    if (!value.trim()) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
        const url = new URL(props.url);
        url.searchParams.set('q', value.trim());
        url.searchParams.set('per_page', '12');
        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error();
            results.value = (await response.json()).products;
            status.value = results.value.length ? '' : 'No matching products.';
            for (const item of results.value) names.value[item.entry_id] = item.product_title;
        } catch (error) {
            if (!controller.signal.aborted) status.value = __('Product search is unavailable. Try again.');
        }
    }, 200);
    cleanup(() => { clearTimeout(timer); controller.abort(); });
});
</script>
<template>
    <label>{{ __('Find a product or variant') }}<input v-model="search" type="search" :placeholder="__('Product name or SKU')"></label>
    <p v-if="status" role="status">{{ status }}</p>
    <ul v-if="results.length">
        <li v-for="item in results" :key="item.sku">
            {{ item.title }} · {{ item.sku }}
            <button type="button" class="ss-text-link" :disabled="skus.includes(item.sku)" @click="skus = [...skus, item.sku]">{{ __('Add SKU') }}</button>
            <button type="button" class="ss-text-link" :disabled="products.includes(item.entry_id)" @click="products = [...products, item.entry_id]">{{ __('Add whole product') }}</button>
        </li>
    </ul>
    <p v-if="!skus.length && !products.length">{{ __('Applies to all products.') }}</p>
    <ul v-else>
        <li v-for="sku in skus" :key="sku">{{ sku }} <button type="button" @click="skus = skus.filter(s => s !== sku)">{{ __('Remove') }}</button></li>
        <li v-for="id in products" :key="id">{{ names[id] || savedNames[id] || id }} {{ __('· All variants') }} <button type="button" @click="products = products.filter(p => p !== id)">{{ __('Remove') }}</button></li>
    </ul>
</template>
