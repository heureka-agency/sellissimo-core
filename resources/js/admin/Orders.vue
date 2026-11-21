<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link } from '@statamic/cms/inertia';
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, Field, Input, Modal, Select, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import Listing from './Listing.vue';
import PaymentBadge from './PaymentBadge.vue';

const props = defineProps({ pickUrl: String, lineExportUrl: String, customFields: Object, columns: String, columnOptions: Object, savedViews: Array, saveViewUrl: String, packingUrl: String, results: Object, filters: Object, sites: Object, listUrl: String, exportUrl: String });
const name = ref('');
const selected = ref([]);
const personalDefault = ref(false);
const panel = ref('');
const payment = ref('');
const sort = ref('');
const visible = computed(() => props.columns.split(',').slice(1));
const edited = ref([]);
watch(() => props.columns, () => { edited.value = [...visible.value]; }, { immediate: true });
const chosen = computed(() => ['order', ...edited.value].join(','));
function move(index, delta) {
    const reordered = [...edited.value];
    reordered.splice(index + delta, 0, reordered.splice(index, 1)[0]);
    edited.value = reordered;
}
const field = ref(props.filters.field || '');
const fieldValue = ref(props.filters.field_value || '');
const dates = ref({ from: props.filters.from || '', to: props.filters.to || '' });
const printable = computed(() => props.results.data.filter(order => order.canPack).map(order => order.id));
const pickLink = computed(() => props.pickUrl && printUrl.value ? props.pickUrl + new URL(printUrl.value).search : '');
const printUrl = computed(() => {
    if (!props.packingUrl) return '';
    const url = new URL(props.packingUrl);
    selected.value.forEach(id => url.searchParams.append('orders[]', id));
    return url.href;
});
const states = ['paid', 'pending', 'awaiting_collection', 'refund_pending', 'refund_due', 'partially_refunded', 'refunded', 'unpaid'];
const label = state => state.charAt(0).toUpperCase() + state.slice(1).replaceAll('_', ' ');
const paymentOptions = [{ value: '/', label: __('All statuses') }, ...states.map(value => ({ value, label: __(label(value)) }))];
const sorts = [
    { value: 'newest', label: __('Newest first') },
    { value: 'oldest', label: __('Oldest first') },
    { value: 'number', label: __('Order number') },
];
function openPanel(kind) {
    if (kind === 'filters') {
        payment.value = props.filters.payment || '/';
        sort.value = props.filters.sort || 'newest';
        dates.value = { from: props.filters.from || '', to: props.filters.to || '' };
        field.value = props.filters.field || '';
        fieldValue.value = props.filters.field_value ?? '';
    }
    if (kind === 'columns') edited.value = [...visible.value];
    if (kind === 'views') {
        personalDefault.value = false;
        name.value = '';
    }
    panel.value = kind;
}
function applyFilters(search) {
    panel.value = '';
    search({ ...dates.value, payment: payment.value === '/' ? '' : payment.value, status: payment.value === '/' && !props.filters.payment ? props.filters.status : '',
        sort: sort.value, field: field.value, field_value: field.value ? fieldValue.value : '' });
}
const tabs = [
    { value: '', label: 'All orders' },
    { value: 'awaiting', label: 'Awaiting shipment', empty: 'No orders awaiting shipment' },
    { value: 'shipped', label: 'Shipped', empty: 'No shipped orders' },
    { value: 'returns', label: 'Open returns', empty: 'No orders with open returns' },
    { value: 'disputes', label: 'Payment disputes', empty: 'No orders with payment disputes' },
];
</script>
<template>
    <Listing
        :title="__('Orders')"
        icon="shopping-cart"
        :results
        :filters
        :sites
        :list-url
        :tabs
        :field-labels="customFields"
        :search-label="__('Order number, name or email')"
        :empty="__('No orders yet')"
        :empty-hint="__('Customer orders will appear here after checkout.')"
    >
        <template #action>
            <Dropdown v-if="results.total && (exportUrl || lineExportUrl)">
                <template #trigger><Button :text="__('Export')" /></template>
                <DropdownMenu>
                    <DropdownItem v-if="exportUrl" as="a" :href="exportUrl" download :text="__('Export CSV')" />
                    <DropdownItem v-if="lineExportUrl" as="a" :href="lineExportUrl" download :text="__('Export order lines')" />
                </DropdownMenu>
            </Dropdown>
        </template>
        <template #filters="{ search }">
            <div class="ss-queue-tools">
                <Button :text="__('Filters')" @click="openPanel('filters')" />
                <Button :text="__('Saved views')" @click="openPanel('views')" />
                <Button :text="__('Columns')" @click="openPanel('columns')" />
            </div>
            <Modal :open="panel === 'filters'" :title="__('Filters')" @update:open="open => { if (!open) panel = '' }">
                <form id="ss-order-filter-form" class="ss-form" @submit.prevent="applyFilters(search)">
                    <div class="ss-field-grid">
                        <Field id="ss-payment-filter" :label="__('Payment')">
                            <Select id="ss-payment-filter" v-model="payment" :options="paymentOptions" option-label="label" option-value="value" />
                        </Field>
                        <Field id="ss-order-sort" :label="__('Sort by')">
                            <Select id="ss-order-sort" v-model="sort" :options="sorts" option-label="label" option-value="value" />
                        </Field>
                        <label>{{ __('From') }}<Input v-model="dates.from" type="date" :max="dates.to || undefined" /></label>
                        <label>{{ __('Through') }}<Input v-model="dates.to" type="date" :min="dates.from || undefined" /></label>
                    </div>
                    <div v-if="Object.keys(customFields || {}).length" class="ss-field-grid">
                        <label>{{ __('Order field') }}
                            <select v-model="field" @change="fieldValue = ''"><option value="">{{ __('All fields') }}</option>
                                <option v-for="(item, handle) in customFields" :key="handle" :value="handle">{{ item.label }}</option>
                            </select>
                        </label>
                        <label v-if="field">{{ __('Exact value') }}
                            <select v-if="['select','radio'].includes(customFields[field]?.type)" v-model="fieldValue">
                                <option value=""></option><option v-for="(value, key) in customFields[field].options" :key="key" :value="key">{{ value }}</option>
                            </select>
                            <select v-else-if="customFields[field]?.type === 'toggle'" v-model="fieldValue"><option value=""></option><option value="1">{{ __('Yes') }}</option><option value="0">{{ __('No') }}</option></select>
                            <Input v-else v-model="fieldValue" :type="customFields[field]?.type === 'date' ? 'date' : 'text'" />
                        </label>
                    </div>
                </form>
                <template #footer><div class="ss-dialog-footer ss-actions"><Button :text="__('Cancel')" @click="panel = ''" />
                    <Button type="submit" form="ss-order-filter-form" variant="primary" :text="__('Apply filters')" /></div></template>
            </Modal>
            <Modal :open="panel === 'columns'" :title="__('Order columns')" @update:open="open => { if (!open) panel = '' }">
                <fieldset class="ss-column-options">
                    <legend class="ss-field-help">{{ __('Order number is always shown.') }}</legend>
                    <div v-for="key in [...edited, ...Object.keys(columnOptions).filter(k => !edited.includes(k))]" :key="key" class="ss-column-row">
                        <label><input type="checkbox" v-model="edited" :value="key" /> {{ __(columnOptions[key]) }}</label>
                        <div v-if="edited.includes(key)" class="ss-inline">
                            <Button size="sm" :disabled="edited.indexOf(key) === 0" :aria-label="__('Move up') + ' · ' + __(columnOptions[key])" @click="move(edited.indexOf(key), -1)">↑</Button>
                            <Button size="sm" :disabled="edited.indexOf(key) === edited.length - 1" :aria-label="__('Move down') + ' · ' + __(columnOptions[key])" @click="move(edited.indexOf(key), 1)">↓</Button>
                        </div>
                    </div>
                </fieldset>
                <template #footer><div class="ss-dialog-footer ss-actions"><Button :text="__('Cancel')" @click="panel = ''" />
                    <Button variant="primary" :text="__('Apply columns')" @click="panel = ''; search({ columns: chosen })" /></div></template>
            </Modal>
            <Modal :open="panel === 'views'" :title="__('Saved views')" @update:open="open => { if (!open) panel = '' }">
                <div v-for="view in savedViews" :key="view.name" class="ss-column-row">
                    <Link class="ss-order-link" :href="view.url">{{ view.name }}</Link>
                    <Button size="sm" :text="__('Remove')" @click="router.delete(view.deleteUrl, { preserveScroll: true })" />
                </div>
                <form id="ss-save-view" class="ss-form" @submit.prevent="router.post(saveViewUrl, { ...filters, columns, name, default_columns: personalDefault }, { onSuccess: () => panel = '' })">
                    <label>{{ __('Save view as') }}<Input v-model="name" required maxlength="64" /></label>
                    <label><input type="checkbox" v-model="personalDefault" /> {{ __('Use these columns by default') }}</label>
                </form>
                <template #footer><div class="ss-dialog-footer ss-actions"><Button :text="__('Close')" @click="panel = ''" />
                    <Button form="ss-save-view" type="submit" variant="primary" :text="__('Save view')" :disabled="!name.trim()" /></div></template>
            </Modal>
        </template>
        <template #tools><div v-if="selected.length" class="ss-toolbar">
            <Button v-if="pickUrl" :href="pickLink" as="a" target="_blank" rel="noopener" :text="__('Print warehouse pick list')" />
            <Button :href="printUrl" as="a" target="_blank" rel="noopener" :text="__('Print remaining items')" />
            <span>{{ selected.length }} {{ __('selected') }}</span>
        </div></template>
        <div class="ss-table-wrap ss-orders-table" :class="{ 'ss-custom-columns': columns !== 'order,customer,payment,fulfillment,total' }">
            <Table class="ss-table">
                <TableColumns>
                    <TableColumn v-if="packingUrl"><label class="ss-select-page"><input :aria-label="__('Select all printable orders')" type="checkbox" :disabled="!printable.length" :checked="!!printable.length && selected.length === printable.length" @change="selected = $event.target.checked ? [...printable] : []" /><span>{{ __('Select all printable orders') }}</span></label></TableColumn>
                    <TableColumn>{{ __('Order') }}</TableColumn>
                    <TableColumn v-for="key in visible" :key="key" :class="{ 'ss-wide': ['customer','fulfillment'].includes(key), 'ss-number': key === 'total' }">{{ __(columnOptions[key]) }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="order in results.data" :key="order.id">
                        <TableCell v-if="packingUrl" class="ss-order-select"><input v-if="order.canPack" type="checkbox" v-model="selected" :value="order.id" :aria-label="__('Select') + ' ' + order.number" /></TableCell>
                        <TableCell class="ss-order-name">
                            <div class="ss-order-identity"><Link class="ss-order-link" :href="order.url">{{ order.number }}</Link>
                            <Badge v-if="['cancelled','expired'].includes(order.status)" :text="__(label(order.status))" />
                            <Badge v-if="order.disputeAlert" :text="__('Payment dispute')" color="red" />
                            <Badge v-if="order.openReturns" :text="__('Open return')" color="amber" /></div>
                            <small v-if="!visible.includes('date')" class="ss-wide">{{ order.date }}</small>
                            <small v-if="visible.includes('customer')" class="ss-compact">{{ order.name || order.email }}</small>
                        </TableCell>
                        <TableCell v-for="key in visible" :key="key" :data-label="__(columnOptions[key])"
                            :class="{ 'ss-wide': ['customer','fulfillment'].includes(key), 'ss-number': key === 'total', 'ss-order-states': key === 'payment' }">
                            <template v-if="key === 'customer'">
                                <span>{{ order.name || order.email }}</span><small v-if="order.name">{{ order.email }}</small>
                            </template>
                            <template v-else-if="key === 'payment'">
                                <PaymentBadge :status="order.paymentState" />
                                <small v-if="visible.includes('fulfillment')" class="ss-compact">{{ __(order.fulfillment) }}</small>
                            </template>
                            <Badge v-else-if="key === 'fulfillment'" :text="__(order.fulfillment)" :color="order.fulfillment === 'Shipped' ? 'green' : order.fulfillment === 'Partially shipped' ? 'amber' : 'default'" />
                            <template v-else>{{ key === 'site' ? (sites[order.site] || order.site) : order[key] }}</template>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </div>
    </Listing>
</template>
