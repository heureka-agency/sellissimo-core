<script setup>
import { ref } from 'vue';
import { router } from '@statamic/cms/inertia';
import { Button, Card, Input, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Field, Select } from '@statamic/cms/ui';
import SiteFilter from './SiteFilter.vue';
import SalesTrend from './SalesTrend.vue';
import OperationsPage from './OperationsPage.vue';
const props = defineProps({
    customers: Object,
    previousCustomers: Object,
    trend: Object,
    currencies: [Array, Object],
    products: [Array, Object],
    variants: [Array, Object],
    undated: [Array, Object],
    undatedDisputes: Number,
    unreconciledDisputes: Number,
    filters: Object,
    sites: Object,
    listUrl: String,
});
const selected = ref({ ...props.filters });
const grouping = ref('products');
const metrics = {
    gross: 'Gross merchandise sales',
    discount: 'Discounts',
    returned_merchandise: 'Merchandise refunded',

    tax: 'Net tax',
    shipping: 'Net shipping',
    charges: 'Net order charges',

    chargebacks: 'Chargeback deductions',
    dispute_reversals: 'Chargeback reversals',
    dispute_fees: 'Dispute fees',
    collected: 'Net receipts',
    voucher_issued: 'Gift voucher value issued',
    voucher_sales: 'Net sales of gift vouchers',
    voucher_redeemed: 'Paid with gift vouchers',
    voucher_restored: 'Refunded to gift vouchers',
};
</script>
<template>
    <OperationsPage :title="__('Sales reports')" icon="money-cashier-calculator"
    >
        <Card>
            <form class="ss-filterbar" @submit.prevent="router.get(listUrl, selected)">
                <label>{{ __('From') }}<Input type="date" v-model="selected.from" required /></label>
                <label>{{ __('Through') }}<Input type="date" v-model="selected.to" :min="selected.from" required /></label>
                <SiteFilter :sites :model-value="selected.site" allow-all @update:model-value="site => selected.site = site || null" />
                <Button type="submit" variant="primary" :text="__('Run report')" />
            </form>
        </Card>
        <p class="ss-field-help">
            {{ __('Sales use the payment confirmation date. Older sales without a payment timestamp use the order date. Refunds use their settlement date. Net merchandise sales exclude tax and delivery.') }}
        </p>
        <Card>
            <h2>{{ __('Customers') }}</h2>
            <div class="ss-field-grid">
                <div v-for="(label, key) in { new: 'New customers', returning: 'Returning customers' }" :key="key">
                    <h3>{{ __(label) }}</h3><strong>{{ customers[key] }}</strong>
                    <small>{{ __('Previous period') }} · {{ previousCustomers[key] }}</small>
                </div>
            </div>
            <p class="ss-field-help">{{ __('Each purchase email counts once. Returning customers first purchased before this period in the selected sites.') }}</p>
        </Card>
        <Card v-if="!Object.keys(currencies).length"
            ><p>{{ __('No sales or payment movements in this period.') }}</p></Card
        >
        <Card v-for="(totals, currency) in currencies" :key="currency"
            ><h2>{{ currency }}</h2>
            <dl class="ss-sales-highlights">
                <div v-for="(label, key) in { net: 'Net merchandise sales', orders: 'Orders', refunds: 'Total refunds' }" :key="key">
                    <dt>{{ __(label) }}</dt><dd><strong>{{ totals[key] }}</strong><small>{{ __('Previous period') }} · {{ trend[currency][key] }}</small></dd>
                </div>
            </dl>
            <SalesTrend :trend="trend[currency]" :from="filters.from" :to="filters.to" :currency="currency" />
            <details class="ss-sales-metrics"><summary>{{ __('Sales breakdown') }}</summary>
            <dl class="ss-field-grid">
                <div v-for="(label, key) in metrics">
                    <dt>{{ __(label) }}</dt>
                    <dd>
                        <strong>{{ totals[key] }}</strong>
                    </dd>
                </div>
            </dl></details>
            <Field :id="'sales-breakdown-' + currency" :label="__('Breakdown')">
                <Select :id="'sales-breakdown-' + currency" v-model="grouping"
                    :options="[{ value: 'products', label: __('By product') }, { value: 'variants', label: __('By variant') }]" option-label="label" option-value="value" />
            </Field>
            <div class="ss-table-wrap">
                <Table class="ss-table">
                    <TableColumns>
                            <TableColumn>{{ grouping === 'products' ? __('Product') : __('Variant') }}</TableColumn>
                            <TableColumn>{{ __('Units sold') }}</TableColumn>
                            <TableColumn>{{ __('Gross') }}</TableColumn>
                            <TableColumn>{{ __('Discounts') }}</TableColumn>
                            <TableColumn>{{ __('Merchandise refunds') }}</TableColumn>
                            <TableColumn>{{ __('Net') }}</TableColumn>
                        </TableColumns>
                    <TableRows>
                        <TableRow v-for="row in (grouping === 'products' ? products : variants)[currency] || []">
                            <TableCell>
                                {{ row.label }} <small v-if="row.sku">{{ row.sku }}</small>
                            </TableCell>
                            <TableCell>{{ row.qty }}</TableCell>
                            <TableCell>{{ row.gross }}</TableCell>
                            <TableCell>{{ row.discount }}</TableCell>
                            <TableCell>{{ row.refunds }}</TableCell>
                            <TableCell>{{ row.net }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div></Card
        >
        <p v-if="unreconciledDisputes">{{ __('Historical disputes awaiting payment reconciliation') }} · {{ unreconciledDisputes }}</p>
        <p v-if="undatedDisputes">{{ __('Dispute movements awaiting a provider date') }} · {{ undatedDisputes }}</p>
        <Card v-if="Object.keys(undated).length"
            ><h2>{{ __('Historical refunds without a settlement date') }}</h2>
            <p>{{ __('These amounts fall outside the dated totals.') }}</p>
            <p v-for="(amount, cur) in undated">{{ cur }} · {{ amount }}</p></Card
        ></OperationsPage
    >
</template>
