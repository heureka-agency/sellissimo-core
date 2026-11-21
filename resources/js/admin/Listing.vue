<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@statamic/cms/inertia';
import { Input, Card, Button, Icon } from '@statamic/cms/ui';
import OperationsPage from './OperationsPage.vue';
import QueuePagination from './QueuePagination.vue';
import SiteFilter from './SiteFilter.vue';

const props = defineProps({
    title: String,
    icon: String,
    results: Object,
    filters: Object,
    sites: Object,
    singleSiteScope: Boolean,
    listUrl: String,
    tabs: Array,
    searchLabel: String,
    empty: String,
    emptyHint: String,
    fieldLabels: Object,
    parentTitle: String,
    parentUrl: String,
});
const query = ref(props.filters.q || '');
const switchingTo = ref(null);
watch(() => props.filters.q, value => query.value = value || '');

const filterLabels = { q: 'Search', payment: 'Payment', status: 'Status', from: 'From', to: 'Through', field: 'Order field' };
const chips = computed(() => {
    const active = [];
    for (const [key, label] of Object.entries(filterLabels)) {
        const value = props.filters[key];
        if (value == null || value === '' || (key === 'field' && !String(props.filters.field_value ?? '').trim())) continue;
        const field = props.fieldLabels?.[value];
        const text = key === 'field' ? [field?.label || value, field?.options?.[props.filters.field_value] ?? props.filters.field_value].filter(item => item != null && item !== '').join(' · ')
            : ['status', 'payment'].includes(key) ? __(value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ')) : value;
        active.push({ key, label: __(label), text, changes: key === 'field' ? { field: null, field_value: null } : { [key]: null } });
    }
    return active;
});
const clearUrl = computed(() => url(Object.assign({}, ...chips.value.map(chip => chip.changes))));
const currentTab = computed(() => props.tabs?.find(tab => tab.value === props.filters.view && tab.value));
function url(changes) {
    const target = new URL(props.listUrl);
    for (const [key, value] of Object.entries({ ...props.filters, ...changes, page: null })) {
        if (value !== '' && value != null) target.searchParams.set(key, value);
        else target.searchParams.delete(key);
    }
    return target.href;
}
function search(changes = {}) {
    router.visit(url({ view: switchingTo.value ?? props.filters.view, q: query.value.trim(), ...changes }), { preserveScroll: true });
}
</script>

<template>
    <OperationsPage :title="__(title)" :icon :parent-url :parent-title listing>
        <template v-if="$slots.action" #action><slot name="action" /></template>
        <slot name="navigation" />
        <nav v-if="tabs?.length" class="ss-tabs" :aria-label="__(':title views', { title: __(title) })">
            <Link v-for="tab in tabs" :key="tab.value" :href="url({ view: tab.value })"
                @start="switchingTo = tab.value" @finish="switchingTo === tab.value && (switchingTo = null)"
                :aria-current="(filters.view || '') === tab.value ? 'page' : undefined" preserve-scroll>{{ __(tab.label) }}</Link>
        </nav>
        <Card inset class="ss-list">
            <div class="ss-toolbar">
                <form class="ss-search" role="search" @submit.prevent="search()">
                    <label class="sr-only" for="ss-list-search">{{ __(searchLabel) }}</label>
                    <Input id="ss-list-search" v-model="query" icon="search-magnifying-glass" :placeholder="__(searchLabel)" />
                    <Button type="submit" :text="__('Search')" />
                </form>
                <SiteFilter :sites :allow-all="!singleSiteScope" :model-value="filters.site" @update:model-value="site => search({ site })" />
                <slot name="filters" :search />
            </div>
            <div v-if="chips.length" class="ss-filter-chips" :aria-label="__('Active filters')">
                <Link v-for="chip in chips" :key="chip.key" :href="url(chip.changes)" preserve-scroll
                    :aria-label="__('Remove filter') + ' · ' + chip.label + ' · ' + chip.text">
                    {{ chip.label }} · {{ chip.text }} <Icon name="x" />
                </Link>
                <Link :href="clearUrl" preserve-scroll>{{ __('Clear filters') }}</Link>
            </div>
            <slot name="tools" :search />
            <slot v-if="results.data.length" />
            <div v-else class="ss-empty">
                <Icon :name="icon" />
                <h2>{{ chips.length ? __('No matching results') : __(currentTab?.empty || empty) }}</h2>
                <p v-if="!chips.length && !currentTab && emptyHint">{{ __(emptyHint) }}</p>
            </div>
            <QueuePagination :records="results" />
        </Card>
    </OperationsPage>
</template>
