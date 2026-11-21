<script setup>
import { router } from '@statamic/cms/inertia';
import { Button, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import Listing from './Listing.vue';
defineProps({ results: Object, filters: Object, sites: Object, listUrl: String });
const tabs = [{ value: '', label: 'Inactive checkouts' }, { value: 'recovered', label: 'Recovered carts', empty: 'No recovered carts' }];
</script>
<template>
    <Listing :title="__('Checkout recovery')" icon="shopping-cart" :results :filters :sites :list-url :tabs
        :search-label="__('Search recovery email')" :empty="__('No checkouts in this view')" :empty-hint="__('Inactive carts appear after the configured waiting period.')">
        <div class="ss-table-wrap"><Table class="ss-table"><TableColumns><TableColumn>{{ __('Buyer') }}</TableColumn><TableColumn>{{ __('Items') }}</TableColumn><TableColumn>{{ __('Last active') }}</TableColumn><TableColumn>{{ __('Status') }}</TableColumn></TableColumns>
            <TableRows><TableRow v-for="row in results.data" :key="row.id">
                <TableCell>{{ row.email || __('Anonymous buyer') }}<small>{{ row.site }}</small></TableCell><TableCell>{{ row.count }}</TableCell>
                <TableCell>{{ row.active }}</TableCell><TableCell>{{ __(row.status) }} <Button v-if="row.remindUrl" :text="__('Send reminder')" size="sm" @click="router.post(row.remindUrl)" /></TableCell>
            </TableRow></TableRows>
        </Table></div>
    </Listing>
</template>
