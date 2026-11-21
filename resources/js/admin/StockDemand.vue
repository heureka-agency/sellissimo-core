<script setup>
import { router } from '@statamic/cms/inertia';
import { Card, Icon, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import OperationsPage from './OperationsPage.vue';
import SiteFilter from './SiteFilter.vue';

defineProps({ site: String, sites: Object, results: Object, url: String });
</script>

<template>
    <OperationsPage :title="__('Back-in-stock demand')" icon="package-box-crate" :records="results">
        <template #action><SiteFilter :sites :model-value="site" @update:model-value="site => router.get(url, { site })" /></template>
        <Card inset>
            <div v-if="results.data.length" class="ss-table-wrap">
                <Table class="ss-table">
                    <TableColumns><TableColumn>{{ __('SKU') }}</TableColumn><TableColumn class="ss-number">{{ __('Confirmed shoppers waiting') }}</TableColumn></TableColumns>
                    <TableRows><TableRow v-for="row in results.data" :key="row.sku"><TableCell>{{ row.sku }}</TableCell><TableCell class="ss-number">{{ row.waiting }}</TableCell></TableRow></TableRows>
                </Table>
            </div>
            <div v-else class="ss-empty"><Icon name="package-box-crate" /><h2>{{ __('No shoppers are waiting for a restock.') }}</h2></div>
        </Card>
    </OperationsPage>
</template>
