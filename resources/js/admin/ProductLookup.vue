<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button, Input, Modal } from '@statamic/cms/ui';

const props = defineProps({ id: String, url: String, sku: String, product: Object });
const emit = defineEmits(['select']);
const buttonText = computed(() => props.product ? props.product.title + ' · ' + props.sku
    : props.sku ? __(':sku (unavailable)', { sku: props.sku }) : __('Choose a product'));
const open = ref(false);
const term = ref('');
const page = ref(1);
const lastPage = ref(1);
const results = ref([]);
const loading = ref(false);
const error = ref('');
const retry = ref(0);
const failedSelection = ref(null);
const choosing = ref(false);
let selection;
onBeforeUnmount(() => selection?.abort());
function start() {
    term.value = '';
    page.value = 1;
    open.value = true;
}
watch(term, () => { page.value = 1; }, { flush: 'sync' });
watch([open, term, page, () => props.url, retry], ([visible, search, current], _, cleanup) => {
    selection?.abort();
    choosing.value = false;
    failedSelection.value = null;
    if (!visible) return;
    results.value = [];
    lastPage.value = current;
    loading.value = true;
    error.value = '';
    const controller = new AbortController();
    const timer = setTimeout(async () => {
        const url = new URL(props.url);
        url.searchParams.set('q', search.trim());
        url.searchParams.set('page', current);
        url.searchParams.set('per_page', 12);
        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (controller.signal.aborted) return;
            results.value = data.products;
            lastPage.value = data.pagination.last_page;
        } catch {
            if (!controller.signal.aborted) error.value = __('Could not load products.');
        } finally {
            if (!controller.signal.aborted) loading.value = false;
        }
    }, 180);
    cleanup(() => { clearTimeout(timer); controller.abort(); });
});
async function select(product) {
    selection?.abort();
    const controller = new AbortController();
    selection = controller;
    choosing.value = true;
    failedSelection.value = null;
    error.value = '';
    try {
        const url = new URL(props.url);
        url.searchParams.set('sku', product.sku);
        const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error();
        const details = await response.json();
        if (!controller.signal.aborted) {
            emit('select', details);
            open.value = false;
        }
    } catch {
        if (!controller.signal.aborted) {
            failedSelection.value = product;
            error.value = __('This product could not be loaded. Search again or choose another item.');
        }
    } finally {
        if (selection === controller) choosing.value = false;
    }
}
</script>
<template>
    <Button :id type="button" :text="buttonText" :aria-label="buttonText" @click="start" />
    <Modal v-model:open="open" :title="__('Choose a product or variant')">
        <div class="ss-form">
            <label>{{ __('Search by name or SKU') }}<Input v-model="term" type="search" maxlength="150" /></label>
            <p role="status" v-if="loading || error || !results.length">{{ loading ? __('Searching…') : error || __('No matching products on this site.') }}</p>
            <Button v-if="error" type="button" :text="__('Try again')" @click="failedSelection ? select(failedSelection) : retry++" />
            <ul class="ss-matches" v-if="results.length">
                <li v-for="row in results" :key="row.sku"><button type="button" :disabled="choosing" @click="select(row)"><strong>{{ row.title }}</strong><small>{{ row.sku }}</small></button></li>
            </ul>
            <nav v-if="lastPage > 1" class="ss-actions" :aria-label="__('Product pages')">
                <Button type="button" :text="__('Previous')" :disabled="loading || page === 1" @click="page--" />
                <span>{{ __('Page') }} {{ page }} {{ __('of') }} {{ lastPage }}</span>
                <Button type="button" :text="__('Next')" :disabled="loading || page === lastPage" @click="page++" />
            </nav>
        </div>
    </Modal>
</template>
