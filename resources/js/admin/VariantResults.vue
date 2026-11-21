<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Field, Input } from '@statamic/cms/ui';

const props = defineProps({ rows: Array });
const query = ref('');
const page = ref(1);
const perPage = 10;
const matches = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();
    return term ? props.rows.filter(row => [row.sku, row.label].some(value => String(value || '').toLocaleLowerCase().includes(term))) : props.rows;
});
const pages = computed(() => Math.max(1, Math.ceil(matches.value.length / perPage)));
const first = computed(() => (page.value - 1) * perPage);
watch(query, () => { page.value = 1; });
watch(pages, count => { page.value = Math.min(page.value, count); });
</script>
<template>
    <div class="ss-stack">
        <Field :label="__('Find a variant')"><Input v-model="query" type="search" icon="search-magnifying-glass" :placeholder="__('Search by name or SKU')" /></Field>
        <slot :rows="matches.slice(first, first + perPage)" :matches="matches" />
        <p v-if="!matches.length" role="status">{{ __('No matching variants') }}</p>
        <nav v-else-if="pages > 1" class="variant-pages" :aria-label="__('Variant pages')">
            <span aria-live="polite">{{ __(':from–:to of :count', { from: first + 1, to: Math.min(first + perPage, matches.length), count: matches.length }) }}</span>
            <Button type="button" size="sm" :disabled="page === 1" :text="__('Previous')" @click="page--" />
            <Button type="button" size="sm" :disabled="page >= pages" :text="__('Next')" @click="page++" />
        </nav>
    </div>
</template>
<style scoped>
.variant-pages { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
.variant-pages span { margin-inline-end: auto; }
</style>
