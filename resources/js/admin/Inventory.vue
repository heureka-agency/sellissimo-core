<script setup>
import { onMounted, ref } from 'vue';
import { useForm } from '@statamic/cms/inertia';
import {
    Badge,
    Field,
    Button,
    Input,
    Modal,
    Table,
    TableColumns,
    TableColumn,
    TableRows,
    TableRow,
    TableCell,
} from '@statamic/cms/ui';
import StoreChoice from './StoreChoice.vue';
import Listing from './Listing.vue';

const props = defineProps({
    results: Object,
    filters: Object,
    listUrl: String,
    saveUrl: String,
    locationsUrl: String,
    collections: Array,
    products: Array,
    adjustTarget: Object,
});
const tabs = [
    { value: '', label: 'All stock' },
    { value: 'available', label: 'In stock', empty: 'No items in stock' },
    { value: 'empty', label: 'Out of stock', empty: 'No items out of stock' },
];
const open = ref(false);
const selected = ref(null);
const form = useForm({ sku: '', quantity: '', mode: 'create', previous: null, reason: '' });
function edit(row) {
    selected.value = row;
    form.clearErrors();
    Object.assign(form, {
        sku: row?.sku || '', reason: '',
        quantity: row?.available ?? '',
        previous: row?.available ?? null,
        mode: row?.recorded ? 'edit' : 'create',
    });
    open.value = true;
}
onMounted(() => { if (props.adjustTarget) edit(props.adjustTarget); });
function save() {
    form.post(props.saveUrl, { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>
<template>
    <Listing
        :title="__('Inventory')"
        icon="package-box-crate"
        :results
        :filters
        :list-url
        :tabs
        :search-label="__('Search products or SKUs')"
        :empty="__('No stock levels yet')"
        :empty-hint="__('Choose a physical product or variant to start tracking stock.')"
    >
        <template #action>
            <div class="ss-actions">
                <Button :href="locationsUrl" :text="__('Locations and history')" />
                <Button
                    v-for="collection in collections"
                    :key="collection.url"
                    :href="collection.url"
                    :text="collection.title"
                />
                <Button variant="primary" :text="__('Add stock level')" @click="edit(null)" />
            </div>
        </template>
        <div class="ss-table-wrap">
            <Table class="ss-table">
                <TableColumns>
                    <TableColumn>{{ __('Product') }}</TableColumn>
                    <TableColumn class="ss-wide">{{ __('Availability') }}</TableColumn>
                    <TableColumn class="ss-number">{{ __('Available to sell') }}</TableColumn>
                    <TableColumn><span class="sr-only">{{ __('Actions') }}</span></TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="row in results.data" :key="row.sku">
                        <TableCell>
                            <strong>{{ row.title || row.sku }}</strong>
                            <small v-if="row.title">{{ row.sku }}</small>
                            <small class="ss-compact">{{
                                Number(row.available) ? __('In stock') : __('Out of stock')
                            }}</small>
                        </TableCell>
                        <TableCell class="ss-wide">
                            <Badge
                                :color="Number(row.available) ? 'green' : 'amber'"
                                :text="Number(row.available) ? __('In stock') : __('Out of stock')"
                            />
                        </TableCell>
                        <TableCell class="ss-number">{{ row.available }}</TableCell>
                        <TableCell class="ss-number">
                            <Button
                                size="sm"
                                :text="__('Adjust')"
                                :aria-label="__('Adjust stock for ') + row.sku"
                                @click="edit(row)"
                            />
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </div>
    </Listing>
    <Modal v-model:open="open" :title="form.mode === 'edit' ? __('Adjust stock') : __('Add stock level')">
        <form class="ss-form" @submit.prevent="save">
            <div v-if="selected" class="ss-record">
                <strong>{{ selected.title || selected.sku }}</strong
                ><small>{{ selected.sku }} · {{ selected.available }} {{ __('available now') }}</small>
            </div>
            <Field v-else :label="__('Product or variant')" id="stock-product" :error="form.errors.sku">
                <StoreChoice
                    id="stock-product"
                    v-model="form.sku"
                    :options="products"
                    :placeholder="__('Choose a physical product')"
                />
                <p v-if="!products.length" class="ss-field-help">
                    {{ __('Every available product on this site is already tracked, or the catalog has no physical products.') }}
                </p>
            </Field>
            <label>
                {{ __('Available to sell') }}
                <Input v-model="form.quantity" type="number" min="0" step="1" required max="999999999" />
            </label>
            <label>{{ __('Reason for adjustment') }}<Input v-model="form.reason" maxlength="150" :placeholder="__('Stock count')" /></label>
            <p v-for="error in form.errors" :key="error" role="alert">
                {{ error }}
            </p>
            <div class="ss-actions">
                <Button :text="__('Cancel')" @click="open = false" />
                <Button
                    type="submit"
                    variant="primary"
                    :text="__('Save stock')"
                    :disabled="form.processing || !form.sku"
                />
            </div>
        </form>
    </Modal>
</template>
