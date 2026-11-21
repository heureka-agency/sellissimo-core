<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@statamic/cms/inertia';
import { Button, Card, Input, Select, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Field } from '@statamic/cms/ui';
import StoreChoice from './StoreChoice.vue';
import OperationsPage from './OperationsPage.vue';
const props = defineProps({
    locations: Object,
    balances: [Array, Object],
    movements: Object,
    sku: String,
    skus: Array,
    requestId: String,
    listUrl: String,
    inventoryUrl: String,
    transferUrl: String,
    createUrl: String,
    countUrl: String,
});
const locationOptions = computed(() => Object.entries(props.locations).map(([value, label]) => ({ value, label })));
const location = useForm({ name: '' });
const transfer = useForm({ request_id: props.requestId, sku: props.sku, from: 'main', to: '', quantity: 1 });
watch(
    () => props.requestId,
    (id) => {
        transfer.request_id = id;
        transfer.sku = props.sku;
    },
);
const count = useForm({ sku: props.sku, location: 'main', previous: 0, quantity: 0, reason: '' });
watch(
    () => [props.sku, props.balances, count.location],
    () => {
        count.sku = props.sku;
        count.previous = Number(props.balances[count.location] || 0);
        count.quantity = count.previous;
    },
    { immediate: true },
);
</script>
<template>
    <OperationsPage :title="__('Locations and stock history')" icon="package-box-crate" :records="movements" :parent-url="inventoryUrl" :parent-title="__('Inventory')">
        <Card>
            <div class="ss-form">
                <div class="ss-filter">
                    <label for="stock-history-sku">{{ __('SKU') }}</label>
                    <StoreChoice id="stock-history-sku" :model-value="sku || null" clearable
                        :placeholder="__('All stock movements')" :options="skus.map(value => ({ value, label: value }))"
                        @update:model-value="value => router.get(listUrl, value ? { sku: value } : {})" />
                </div>
                <h2>{{ __('Stock locations') }}</h2>
                <ul class="ss-record-list">
                    <li v-for="(label, code) in locations">
                        {{ label }} <strong v-if="sku"> · {{ balances[code] || 0 }} {{ __('available') }}</strong>
                    </li>
                </ul>
                <form
                    class="ss-actions"
                    @submit.prevent="location.post(createUrl, { onSuccess: () => location.reset() })"
                >
                    <label>{{ __('New location') }}<Input v-model="location.name" maxlength="100" required /></label
                    ><Button type="submit" :text="__('Add location')" />
                </form></div
        ></Card>
        <Card v-if="sku"
            ><h2>{{ __('Count stock at a location') }}</h2>
            <form
                class="ss-form"
                @submit.prevent="count.post(countUrl, { onSuccess: () => (count.reason = '') })"
            >
                <div class="ss-field-grid">
                    <label
                        >{{ __('Location') }}<Select :aria-label="__('Count location')" v-model="count.location" :options="Object.entries(locations).map(([value, label]) => ({ value, label }))" option-label="label" option-value="value" /></label
                    ><label
                        >{{ __('Counted units') }}<Input v-model="count.quantity" type="number" min="0" required /></label
                    ><label>{{ __('Adjustment reason') }}<Input v-model="count.reason" required maxlength="150" /></label>
                </div>
                <div>
                    <Button :text="__('Save location count')" type="submit" :disabled="count.processing" />
                </div></form
        ></Card>
        <Card v-if="sku && Object.keys(locations).length > 1"
            ><h2>{{ __('Transfer stock') }}</h2>
            <form class="ss-form" @submit.prevent="transfer.post(transferUrl)">
                <div class="ss-field-grid">
                    <Field id="transfer-from" :label="__('From')" :error="transfer.errors.from">
                        <Select id="transfer-from" v-model="transfer.from" :options="locationOptions" option-label="label" option-value="value"
                            @update:model-value="value => { if (value === transfer.to) transfer.to = '' }" />
                    </Field>
                    <Field id="transfer-to" :label="__('To')" :error="transfer.errors.to">
                        <Select id="transfer-to" v-model="transfer.to" :placeholder="__('Choose location')" :options="locationOptions.filter(option => option.value !== transfer.from)" option-label="label" option-value="value" />
                    </Field>
                    <label>{{ __('Units') }}<Input type="number" min="1" v-model="transfer.quantity" required /></label>
                </div>
                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        :text="__('Transfer stock')"
                        :disabled="transfer.processing"
                    />
                </div></form
        ></Card>
        <Card
            ><h2>{{ __('Stock movements') }}</h2>
            <p v-if="!movements.data.length">{{ __('No movements recorded.') }}</p>
            <div v-else class="ss-table-wrap">
                <Table class="ss-table">
                    <TableColumns>
                            <TableColumn>{{ __('Date') }}</TableColumn>
                            <TableColumn>{{ __('SKU and location') }}</TableColumn>
                            <TableColumn>{{ __('Change') }}</TableColumn>
                            <TableColumn>{{ __('Balance') }}</TableColumn>
                            <TableColumn>{{ __('Reason') }}</TableColumn>
                        </TableColumns>
                    <TableRows>
                        <TableRow v-for="m in movements.data" :key="m.id">
                            <TableCell>{{ m.created_at }}</TableCell>
                            <TableCell>
                                {{ m.sku }}<small>{{ locations[m.location] }}</small>
                            </TableCell>
                            <TableCell>{{ m.delta > 0 ? '+' : '' }}{{ m.delta }}</TableCell>
                            <TableCell>{{ m.balance }}</TableCell>
                            <TableCell>
                                {{ m.reason
                                }}<small
                                    >{{ m.actor || __('Store') }}
                                    <a v-if="m.orderUrl" :href="m.orderUrl">{{ __('View order') }}</a></small
                                >
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div></Card
        >
    </OperationsPage>
</template>
