<script setup>
import { useSettingsChanges } from './useSettingsChanges';
import SettingsErrors from './SettingsErrors.vue';
import subdivisions from '../../postal-subdivisions.json';
import { computed, ref } from 'vue';
import { Link, useForm } from '@statamic/cms/inertia';
import {
    Field,
    Select,
    Checkbox,
    Stack,
    Button,
    Input,
    Badge,
    Table,
    TableColumns,
    TableColumn,
    TableRows,
    TableRow,
    TableCell,
} from '@statamic/cms/ui';
import CustomerLookup from './CustomerLookup.vue';
import PromotionTargets from './PromotionTargets.vue';
import Listing from './Listing.vue';
import StoreChoice from './StoreChoice.vue';
const props = defineProps({
    storewide: Boolean, resource: String, ruleTypes: Object,
    productNames: Object, accountsUrl: String, accountNames: Object,
    records: Object, editCode: String,
    filters: Object,
    primaryKey: String,
    currencies: Array,
    classes: Object,
    countries: Object,
    urls: Object,
    taxPreferences: Object, taxSite: String, taxSettingsUrl: String, settingsUrl: String,
});
const taxForm = useForm({ site: props.taxSite, physical_address: props.taxPreferences?.physical_address || 'billing', shipping_class: props.taxPreferences?.shipping_class || '' });
const discount = computed(() => props.resource === 'discounts');
const zone = computed(() => props.resource === 'tax-zones');
const title = computed(() => (discount.value ? 'Discounts' : 'Taxes'));
const noun = computed(() => (discount.value ? 'discount' : zone.value ? 'tax zone' : 'tax class'));
const countryChoices = computed(() =>
    Object.entries(props.countries).map(([value, label]) => ({ value, label })),
);
const classChoices = computed(() =>
    Object.entries(props.classes).map(([value, label]) => ({ value, label })),
);
const types = computed(() => ({
    percentage: __('Percentage'), fixed: __('Fixed amount'), free_shipping: __('Free shipping'),
    ...Object.fromEntries(Object.entries(props.ruleTypes || {}).map(([key, rule]) => [key, rule.label])),
}));
const defaults = () => ({
    code: '',
    site: props.storewide ? null : props.taxSite, automatic: false, combinable: false, target_products: [], target_skus: [], target_customers: [],
    priority: 10,
    usage_limit: null,
    per_customer_limit: null,
    handle: '',
    name: '',
    revision: 0,
    type: 'percentage', rule_settings: {},
    currency: props.currencies[0],
    value: '',
    minimum: '0',
    starts_on: '',
    ends_on: '',
    enabled: true,
    inclusive: false,
    tax_class: props.classes.standard ? 'standard' : Object.keys(props.classes)[0] || '',
    country: '',
    region: '',
    postcode: '',
    rate: '',
    id: '',
});
const offerOptions = computed(() => {
    const labels = { ...types.value };
    if (!labels[form.type]) labels[form.type] = __('Extension unavailable');
    return Object.entries(labels).map(([value, label]) => ({ value, label }));
});
const open = ref(false);
const form = useForm(defaults());
const selectedNames = ref({});
function selectAccount(account) {
    selectedNames.value[account.customer_id] = account.email;
    if (!form.target_customers.includes(account.customer_id)) form.target_customers = [...form.target_customers, account.customer_id];
}
function changeType() {
    const fields = props.ruleTypes?.[form.type]?.fields || {};
    form.rule_settings = Object.fromEntries(Object.entries(fields)
        .filter(([, field]) => field.default !== undefined)
        .map(([key, field]) => [key, field.default]));
}
function edit(row) {
    form.clearErrors();
    Object.assign(form, defaults(), row);
    form.defaults();
    open.value = true;
}
function usageText(row) {
    return row.usage_limit
        ? __(':used of :limit uses', { used: row.uses, limit: row.usage_limit })
        : __('Uses: :count', { count: row.uses });
}
const changes = useSettingsChanges(() => (open.value && form.isDirty) || taxForm.isDirty);
function save() {
    changes.submit(() => form.post(props.urls[props.resource], { preserveScroll: true, onSuccess: () => (open.value = false) }));
}
function closeEditor(value) {
    if (value || !form.isDirty || changes.mayClose()) open.value = value;
}
function saveTaxes() {
    changes.submit(() => taxForm.post(props.taxSettingsUrl, { preserveScroll: true, onSuccess: () => taxForm.defaults() }));
}
if (props.editCode) {
    const record = props.records.data.find(row => row.code === props.editCode);
    if (record) edit(record);
}
</script>
<template>

    <Listing
        :title
        :parent-url="settingsUrl" :parent-title="__('Taxes and pricing')"
        :icon="discount ? 'money-cashier-price-tag' : 'money-cashier-calculator'"
        :results="records"
        :filters
        :list-url="urls[resource]"
        :search-label="
            discount
                ? 'Search promotion name or code'
                : zone
                  ? 'Search zone or destination'
                  : 'Search tax classes'
        "
        :empty="discount ? __('No discounts yet') : zone ? __('No tax zones yet') : __('No tax classes yet')"
        :empty-hint="
            discount
                ? 'Create an automatic offer or a coupon for checkout.'
                : zone
                  ? 'Choose where a rate applies to a product tax class.'
                  : 'Group products that share the same tax treatment.'
        "
    >
        <template #action><Button v-if="discount || storewide" variant="primary" :text="__('Create ' + noun)" @click="edit(null)" /></template>
        <template #navigation>
            <p v-if="!discount">{{ __('Tax classes and zones apply to all sites.') }}</p>
            <nav v-if="!discount" :aria-label="__('Tax settings')" class="ss-tabs">
                <Link
                    v-for="(label, key) in { 'tax-classes': 'Classes', 'tax-zones': 'Zones' }"
                    :key
                    :href="urls[key]"
                    :aria-current="resource === key ? 'page' : undefined"
                    >{{ __(label) }}</Link
                >
            </nav>
            <details v-if="!discount" class="ss-body"><summary>{{ __('Tax calculation settings ·') }} {{ taxSite }}</summary>
        <form class="ss-form" @submit.prevent="saveTaxes">
            <Field :label="__('Tax physical goods using')"><Select v-model="taxForm.physical_address" :options="[{ value: 'billing', label: __('Billing address') }, { value: 'shipping', label: __('Delivery address') }]" /></Field>
            <Field :label="__('Delivery charge tax class')"><Select v-model="taxForm.shipping_class" :options="[{value: '', label: __('No delivery tax')}, ...classChoices]" /></Field>
            <SettingsErrors :errors="taxForm.errors" />
            <Button type="submit" :text="__('Save tax settings')" :disabled="!taxForm.isDirty || taxForm.processing" />
        </form>
    </details>
        </template>
        <div class="ss-table-wrap">
            <Table class="ss-table">
                <TableColumns
                    ><TableColumn>{{ discount ? __('Promotion') : __('Name') }}</TableColumn
                    ><TableColumn>{{ discount ? __('Offer') : zone ? __('Rate') : __('Product prices') }}</TableColumn
                    ><TableColumn v-if="zone">{{ __('Destination') }}</TableColumn
                    ><TableColumn v-if="discount || zone">{{ __('Status') }}</TableColumn
                    ><TableColumn><span class="sr-only">{{ __('Actions') }}</span></TableColumn></TableColumns
                >
                <TableRows
                    ><TableRow v-for="row in records.data" :key="row[primaryKey]">
                        <TableCell
                            ><strong>{{ row.name || row.code }}</strong
                            ><small v-if="discount">{{
                                row.automatic ? __('Automatic · Priority ') + row.priority : __('Coupon')
                            }}</small
                            ><small v-if="row.tax_class">{{
                                row.tax_class ? classes[row.tax_class] || row.tax_class : ''
                            }}</small></TableCell
                        >
                        <TableCell v-if="discount"
                            >{{
                                row.type?.startsWith('extension:') ? (types[row.type] || __('Extension unavailable')) : row.type === 'free_shipping'
                                    ? __('Free shipping')
                                    : row.value + (row.type === 'percentage' ? '%' : ' ' + row.currency)
                            }}<small>{{ row.currency }} {{ __('· Minimum') }} {{ row.minimum }}</small>
                            <small v-if="!row.automatic"
                                >{{ usageText(row)
                                }}<span v-if="row.per_customer_limit">
                                    · {{ row.per_customer_limit }} {{ __('per customer') }}</span
                                ></small
                            ></TableCell
                        >
                        <TableCell v-else>{{
                            zone ? row.rate + '%' : row.inclusive ? __('Include tax') : __('Exclude tax')
                        }}</TableCell>
                        <TableCell v-if="zone">{{
                            [countries[row.country] || row.country, row.region, row.postcode]
                                .filter(Boolean)
                                .join(' · ')
                        }}</TableCell>
                        <TableCell v-if="discount || zone"
                            ><Badge
                                :color="
                                    row.state === 'Scheduled'
                                        ? 'amber'
                                        : (!row.state || row.state === 'Enabled') && row.enabled
                                          ? 'green'
                                          : 'default'
                                "
                                :text="(row.state && __(row.state)) || (row.enabled ? __('Enabled') : __('Disabled'))"
                        /></TableCell>
                        <TableCell
                            ><Button
                                v-if="storewide || (discount && row.site)" size="sm"
                                :text="__('Edit')"
                                :aria-label="__('Edit ') + (row.name || row.code)"
                                @click="edit(row)"
                        /></TableCell> </TableRow
                ></TableRows>
            </Table>
        </div>
    </Listing>
    <Stack :open @update:open="closeEditor" size="half" :title="__((form.revision ? 'Edit ' : 'Create ') + noun)">
        <form id="ss-pricing-rule" @submit.prevent="save">
            <div class="ss-form">
                <SettingsErrors :errors="form.errors" />
                <template v-if="discount">
                    <fieldset class="ss-group">
                        <legend>{{ __('Promotion') }}</legend>
                        <p v-if="form.site">{{ __('Available on site') }} {{ form.site }}</p>
                        <Field :label="__('Application method')">
                            <Select :model-value="form.automatic ? 'automatic' : 'coupon'" :disabled="!!form.revision"
                                :options="[{ value: 'coupon', label: __('Coupon code') }, { value: 'automatic', label: __('Automatic offer') }]"
                                @update:model-value="value => form.automatic = value === 'automatic'" />
                        </Field>
                        <template v-if="form.automatic">
                            <div class="ss-field-grid">
                                <label
                                    >{{ __('Offer name') }}<Input
                                        v-model="form.name"
                                        required
                                        maxlength="100"
                                        :placeholder="__('Summer sale')"
                                /></label>
                                <label
                                    >{{ __('Priority') }}<Input
                                        v-model="form.priority"
                                        type="number"
                                        required
                                        min="1"
                                        max="9999"
                                /></label>
                            </div>
                            <small class="ss-field-help"
                                >{{ __('The lowest priority number applies first. A customer’s coupon takes precedence.') }}</small
                            >
                        </template>
                        <label v-else
                            >{{ __('Coupon code') }}<Input
                                v-model="form.code"
                                required
                                :read-only="!!form.revision"
                                maxlength="80"
                                :placeholder="__('WELCOME10')"
                        /></label>
                    </fieldset>
                    <fieldset class="ss-group">
                        <legend>{{ __('Eligible products') }}</legend>
                        <PromotionTargets v-model:skus="form.target_skus" v-model:products="form.target_products" :url="urls.catalog" :saved-names="productNames" />
                        <Checkbox v-model="form.combinable" :label="__('Allow this promotion to combine with other combinable offers')" />
                    </fieldset>
                    <fieldset class="ss-group">
                        <legend>{{ __('Eligible customers') }}</legend>
                        <p v-if="!form.target_customers.length">{{ __('Available to all buyers.') }}</p>
                        <ul v-else><li v-for="id in form.target_customers" :key="id">{{ selectedNames[id] || accountNames[id] || __('Unavailable account') }}
                            <Button type="button" size="sm" variant="ghost" :text="__('Remove')" @click="form.target_customers = form.target_customers.filter(customer => customer !== id)" />
                        </li></ul>
                        <CustomerLookup :url="accountsUrl" accounts-only @select="selectAccount" />
                        <p role="alert" v-if="form.errors.target_customers">{{ form.errors.target_customers }}</p>
                    </fieldset>
                    <fieldset class="ss-group">
                        <legend>{{ __('Offer value') }}</legend>
                        <div class="ss-field-grid">
                            <Field :label="__('Offer type')">
                                <Select :model-value="form.type" :options="offerOptions" @update:model-value="value => { form.type = value; changeType(); }" />
                            </Field>
                            <Field :label="__('Currency')"><Select v-model="form.currency" :options="currencies.map(code => ({ value: code, label: code }))" /></Field>
                        </div>
                        <div class="ss-field-grid">
                            <Field v-for="(field, key) in ruleTypes?.[form.type]?.fields" :key="key"
                                :label="field.label" :error="form.errors['rule_settings.' + key]" :required="field.required">
                                <Select v-if="field.type === 'select'" v-model="form.rule_settings[key]" :placeholder="__('Choose an option')"
                                    :options="Object.entries(field.options).map(([value, label]) => ({ value, label }))" :clearable="!field.required" />
                                <Checkbox v-else-if="field.type === 'toggle'" v-model="form.rule_settings[key]" :aria-label="field.label" />
                                <Input v-else v-model="form.rule_settings[key]" :type="field.type === 'integer' ? 'number' : 'text'"
                                    :min="field.min" :max="field.max" :required="field.required" />
                            </Field>
                            <label v-if="['fixed', 'percentage'].includes(form.type)"
                                >{{
                                    form.type === 'fixed'
                                        ? __('Amount (') + form.currency + ')'
                                        : __('Percentage (%)')
                                }}
                                <Input v-model="form.value" inputmode="decimal" required
                            /></label>
                            <label
                                >{{ __('Minimum spend (') }}{{ form.currency }})<Input
                                    v-model="form.minimum"
                                    inputmode="decimal"
                            /></label>
                        </div>
                    </fieldset>
                    <fieldset class="ss-group">
                        <legend>{{ __('Availability') }}</legend>
                        <div v-if="!form.automatic" class="ss-field-grid">
                            <label
                                >{{ __('Total uses allowed') }}<Input
                                    v-model="form.usage_limit"
                                    type="number"
                                    min="1"
                                    max="1000000000"
                                    :placeholder="__('Unlimited')"
                            /></label>
                            <label
                                >{{ __('Uses per customer') }}<Input
                                    v-model="form.per_customer_limit"
                                    type="number"
                                    min="1"
                                    max="1000000000"
                                    :placeholder="__('Unlimited')"
                            /></label>
                        </div>
                        <div class="ss-field-grid">
                            <label>{{ __('Starts on') }}<Input v-model="form.starts_on" type="date" /></label
                            ><label
                                >{{ __('Ends on') }}<Input
                                    v-model="form.ends_on"
                                    type="date"
                                    :min="form.starts_on || undefined"
                            /></label>
                        </div>
                        <Checkbox v-model="form.enabled" :label="__('Enable promotion')" />
                    </fieldset>
                </template>
                <template v-else-if="zone">
                    <fieldset class="ss-group">
                        <legend>{{ __('Tax rule') }}</legend>
                        <label
                            >{{ __('Zone name') }}<Input
                                v-model="form.name"
                                required
                                maxlength="100"
                                :placeholder="__('California sales tax')"
                        /></label>
                        <div class="ss-field-grid">
                            <Field :label="__('Product tax class')" id="zone-class" :error="form.errors.tax_class">
                                <StoreChoice
                                    id="zone-class"
                                    v-model="form.tax_class"
                                    :options="classChoices"
                                    :placeholder="__('Choose a class')"
                            /></Field>
                            <label
                                >{{ __('Tax rate (%)') }}<Input v-model="form.rate" required inputmode="decimal"
                            /></label>
                        </div>
                    </fieldset>
                    <fieldset class="ss-group">
                        <legend>{{ __('Destination') }}</legend>
                        <Field :label="__('Country')" id="zone-country" :error="form.errors.country"
                            ><StoreChoice
                                id="zone-country"
                                v-model="form.country"
                                :options="countryChoices"
                                :placeholder="__('Choose a country')"
                        /></Field>
                        <div class="ss-field-grid">
                            <label
                                >{{ __('State or region') }}<Input v-model="form.region" list="sellissimo-tax-regions" :placeholder="__('All regions')"
                            /><datalist id="sellissimo-tax-regions"><option v-for="[value, label] in subdivisions[form.country] || []" :key="value" :value="value">{{ label }}</option></datalist></label>
                            <label
                                >{{ __('Postal code') }}<Input v-model="form.postcode" :placeholder="__('All postal codes')"
                            /></label>
                        </div>
                        <small class="ss-field-help"
                            >{{ __('Leave either field empty to include all matches. Use * for a postal code prefix, such as 902*.') }}</small
                        >
                        <Checkbox v-model="form.enabled" :label="__('Enable tax zone')" />
                    </fieldset>
                </template>
                <fieldset v-else class="ss-group">
                    <legend class="sr-only">{{ __('Tax class details') }}</legend>
                    <label
                        >{{ __('Class name') }}<Input
                            v-model="form.name"
                            required
                            maxlength="100"
                            :placeholder="__('Reduced rate')"
                    /></label>
                    <Checkbox v-model="form.inclusive" :label="__('Catalog prices include tax')" />
                    <details class="ss-disclosure">
                        <summary>{{ __('Class identifier') }}</summary>
                        <label
                            >{{ __('Handle') }}<Input
                                v-model="form.handle"
                                :read-only="!!form.revision"
                                :placeholder="__('Generated from class name')"
                        /></label>
                        <small class="ss-field-help"
                            >{{ __('Products keep this identifier when you rename the class.') }}</small
                        >
                    </details>
                </fieldset>
            </div>
        </form>
        <template #footer-end>
            <div class="ss-dialog-footer">
                <div class="ss-actions">
                    <Button type="button" :text="__('Cancel')" @click="closeEditor(false)" />
                    <Button
                        form="ss-pricing-rule"
                        type="submit"
                        variant="primary"
                        :text="__('Save changes')"
                        :disabled="form.processing || (zone && (!form.country || !form.tax_class))"
                    />
                </div>
            </div>
        </template>
    </Stack>
</template>
