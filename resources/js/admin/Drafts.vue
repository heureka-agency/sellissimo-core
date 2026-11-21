<script setup>
import Listing from './Listing.vue';
import { Link } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Table,
    TableColumns,
    TableColumn,
    TableRows,
    TableRow,
    TableCell,
} from '@statamic/cms/ui';
defineProps({ records: Object, filters: Object, sites: Object, listUrl: String, createUrl: String });
const tabs = [
    { value: '', label: 'All drafts' },
    { value: 'open', label: 'Open', empty: 'No open drafts' },
    { value: 'ordered', label: 'Ordered', empty: 'No drafts converted to orders' },
    { value: 'cancelled', label: 'Cancelled', empty: 'No cancelled drafts' },
];
</script>
<template>
    <Listing
        :title="__('Draft orders')"
        icon="add-entry"
        :results="records"
        :filters
        :sites
        :list-url
        :tabs
        :search-label="__('Search customer name or email')"
        :empty="__('No draft orders yet')"
        :empty-hint="__('Prepare an order for a customer and share a payment link.')"
    >
        <template #action><Button variant="primary" :href="createUrl" :text="__('Create draft')" /></template>
        <div class="ss-table-wrap">
            <Table class="ss-table"
                ><TableColumns
                    ><TableColumn>{{ __('Customer') }}</TableColumn><TableColumn class="ss-wide">{{ __('Items') }}</TableColumn
                    ><TableColumn class="ss-wide">{{ __('Created') }}</TableColumn
                    ><TableColumn>{{ __('Status') }}</TableColumn></TableColumns
                >
                <TableRows
                    ><TableRow v-for="row in records.data" :key="row.id"
                        ><TableCell
                            ><Link class="ss-order-link" :href="row.url">{{ row.name || row.email || __('Unassigned draft') }}</Link
                            ><small v-if="row.name">{{ row.email }}</small
                            ><small class="ss-compact"
                                >{{ row.items }} {{ row.items === 1 ? 'item' : 'items' }} ·
                                {{ row.date }}</small
                            ></TableCell
                        ><TableCell class="ss-wide">{{ row.items }}</TableCell
                        ><TableCell class="ss-wide">{{ row.date }}</TableCell
                        ><TableCell
                            ><Badge
                                :text="__(row.state)"
                                :color="
                                    row.state === 'Ordered'
                                        ? 'green'
                                        : row.state === 'Draft'
                                          ? 'blue'
                                          : 'default'
                                " /></TableCell></TableRow></TableRows
            ></Table>
        </div>
    </Listing>
</template>
