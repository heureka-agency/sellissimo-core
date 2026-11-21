<script setup>
import { computed, ref, watch } from 'vue';
import VariantBrowser from './VariantBrowser.vue';
import { variantRowId } from './variantRowId';
import VariantResults from './VariantResults.vue';
import { Fieldtype } from '@statamic/cms';
import { Button, Checkbox, CheckboxGroup, Field, Input, Stack, Select, injectPublishContext } from '@statamic/cms/ui';

const props = defineProps(Fieldtype.props);
const emit = defineEmits(Fieldtype.emits);
const { expose, update, isReadOnly, fieldPathKeys } = Fieldtype.use(emit, props);
defineExpose(expose);
const rows = computed(() => props.value || []);
const editingRows = ref(!rows.value.length);
const context = injectPublishContext();
const largeCatalog = computed(() => rows.value.length > 10);
const metaPath = computed(() => [props.metaPathPrefix, props.handle].filter(Boolean).join('.'));
watch(() => Object.keys(context?.errors?.value || {}), paths => {
    const key = fieldPathKeys.value.join('.');
    if (paths.some(path => path === key || path.startsWith(key + '.'))) editingRows.value = true;
}, { immediate: true });
const prices = row => Object.entries(row.prices || {}).filter(([, value]) => value !== '' && value != null)
    .map(([code, value]) => code + ' ' + value).join(' · ');
const mode = ref('');
const open = computed({ get: () => !!mode.value, set: visible => { if (!visible) mode.value = ''; } });
const dimensions = ref([]);
const prefix = ref('');
const selected = ref([]);
const field = ref('prices');
const amount = ref('');
const currency = ref('');
const currencies = computed(() => props.meta.new?.prices?.currencies || []);
const fields = { prices: 'Selling price', sale_prices: 'Sale price', costs: 'Internal cost', weight_kg: 'Packaged weight (kg)' };
const keyed = value => JSON.stringify(Object.entries(value || {}).map(([k,v]) => [k.trim().toLowerCase(), String(v).trim().toLowerCase()]).sort(([a],[b]) => a.localeCompare(b)));
const token = value => value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, '');
const clone = value => JSON.parse(JSON.stringify(value));
function start(which) {
    mode.value = which;
    selected.value = [];
    amount.value = '';
    field.value = 'prices';
    currency.value = currencies.value[0] || '';
    dimensions.value = [{ name: '', values: '' }];
    prefix.value = '';
}
const plan = computed(() => {
    const error = message => ({ message: __(message), additions: [], skipped: 0 });
    if (mode.value !== 'generate' || !prefix.value.trim()) return error('Enter a SKU prefix.');
    const stem = prefix.value.trim().toUpperCase();
    if (!/^[A-Z0-9][A-Z0-9_.-]*$/.test(stem)) return error('Use letters, numbers, dots, underscores or hyphens in the SKU prefix.');
    const usedNames = new Set();
    let combinations = [[]];
    for (const dim of dimensions.value) {
        const name = dim.name.trim();
        const values = dim.values.split(',').map(v => v.trim()).filter(Boolean);
        if (!name || !values.length) return error('Enter an attribute and at least one value per row.');
        if (usedNames.has(name.toLowerCase()) || new Set(values.map(v => v.toLowerCase())).size !== values.length) return error('Use distinct attribute names and values.');
        usedNames.add(name.toLowerCase());
        if (combinations.length * values.length > 1000) return error('Generate up to 1,000 combinations at a time.');
        combinations = combinations.flatMap(combo => values.map((value, index) => [...combo, { name, value, suffix: token(value) || String(index + 1) }]));
    }
    const existing = new Set(rows.value.map(row => keyed(row.attributes)));
    const skus = new Set(rows.value.map(row => (row.sku || '').toUpperCase()));
    const additions = [];
    for (const combo of combinations) {
        const attributes = Object.fromEntries(combo.map(({ name, value }) => [name, value]));
        if (existing.has(keyed(attributes))) continue;
        const sku = [stem, ...combo.map(v => v.suffix)].join('-');
        if (sku.length > 100 || skus.has(sku)) return error(__('Generated SKU :sku is too long or already in use. Change the prefix or values.', { sku }));
        skus.add(sku);
        additions.push({ sku, attributes, label: combo.map(v => v.value).join(' / ') });
    }
    if (props.config.max_rows && rows.value.length + additions.length > props.config.max_rows) return error('The combinations exceed the maximum number of rows for this field.');
    return { additions, skipped: combinations.length - additions.length, message: '' };
});
function generate() {
    if (!plan.value.additions.length || isReadOnly.value) return;
    const existing = { ...props.meta.existing };
    const newRows = plan.value.additions.map(row => {
        const id = variantRowId();
        existing[id] = clone(props.meta.new);
        return { ...clone(props.meta.defaults), ...row, _id: id };
    });
    emit('update:meta', { ...props.meta, existing });
    editingRows.value = true;
    update([...rows.value, ...newRows]);
    mode.value = '';
}
const all = computed({
    get: () => rows.value.length > 0 && selected.value.length === rows.value.length,
    set: checked => { selected.value = checked ? rows.value.map(row => row._id) : []; },
});
const validAmount = computed(() => {
    if (field.value === 'weight_kg') return /^\d+(\.\d+)?$/.test(amount.value) && Number.isFinite(Number(amount.value));
    if (!currency.value) return false;
    const digits = props.meta.new?.prices?.digits?.[currency.value];
    if (!Number.isInteger(digits)) return false;
    return new RegExp('^\\d+' + (digits ? '(\\.\\d{1,' + digits + '})?' : '') + '$').test(amount.value) && Math.round(Number(amount.value) * 10 ** digits) <= 99999999;
});
function applyBulk() {
    if (!validAmount.value || !selected.value.length || isReadOnly.value) return;
    const ids = new Set(selected.value);
    update(rows.value.map(row => ids.has(row._id) ? { ...row, [field.value]: field.value === 'weight_kg'
        ? Number(amount.value) : { ...row[field.value], [currency.value]: amount.value } } : row));
    mode.value = '';
}
</script>
<template>
    <div class="ss-stack">
        <div v-if="!isReadOnly" class="ss-actions">
            <Button type="button" size="sm" :text="__('Generate variants')" @click="start('generate')" />
            <Button v-if="rows.length" type="button" size="sm" :text="__('Bulk edit variants')" @click="start('bulk')" />
        </div>
        <VariantBrowser v-if="largeCatalog" :rows :config :meta :read-only="isReadOnly" :field-path="fieldPathKeys.join('.')" :meta-path="metaPath"
            :errors="context?.errors?.value" @update="update" @metadata="emit('update:meta', $event)" />
        <template v-else-if="rows.length">
            <dl class="variant-summary">
                <div v-for="row in rows.slice(0, 5)" :key="row._id">
                    <dt><strong>{{ row.label || row.sku }}</strong> <small v-if="row.label">{{ row.sku }}</small></dt>
                    <dd>{{ prices(row) || __('No price') }}</dd>
                </div>
            </dl>
            <div class="variant-controls">
                <span v-if="rows.length > 5">{{ __('Showing 5 of :count variants', { count: rows.length }) }}</span>
                <Button size="sm" :aria-expanded="editingRows" :aria-controls="id + '-rows'" :text="editingRows ? __('Hide variant details') : __('Show variant details')"
                    @click="editingRows = !editingRows" />
            </div>
        </template>
        <div v-if="!largeCatalog && (!rows.length || editingRows)" :id="id + '-rows'"><grid-fieldtype v-bind="props" @update:value="update" @update:meta="emit('update:meta', $event)" @focus="emit('focus')" @blur="emit('blur')" /></div>
        <Stack v-model:open="open" size="half" :title="mode === 'generate' ? __('Generate variants') : __('Bulk edit variants')">
            <div v-if="mode === 'generate'" class="ss-form">
                <label>{{ __('SKU prefix') }}<Input v-model="prefix" :placeholder="__('SHIRT')" /></label>
                <div v-for="(dimension, i) in dimensions" :key="i" class="ss-field-grid">
                    <label>{{ __('Attribute') }}<Input v-model="dimension.name" :placeholder="__('Size')" /></label>
                    <label>{{ __('Values separated by commas') }}<Input v-model="dimension.values" :placeholder="__('Small, Medium, Large')" /></label>
                    <Button v-if="dimensions.length > 1" type="button" size="sm" :text="__('Remove attribute')" @click="dimensions.splice(i, 1)" />
                </div>
                <Button type="button" size="sm" :text="__('Add attribute')" @click="dimensions.push({name: '', values: ''})" />
                <p role="status">{{ plan.message || __(':new new variants · :existing existing combinations', { new: plan.additions.length, existing: plan.skipped }) }}</p>
                <ul v-if="plan.additions.length"><li v-for="row in plan.additions.slice(0, 5)" :key="row.sku">{{ row.label }} · {{ row.sku }}</li></ul>
            </div>
            <div v-else-if="mode === 'bulk'" class="ss-form">
                <fieldset><legend>{{ __('Variants to update') }}</legend>
                    <Checkbox v-model="all" :label="__('Select all variants')" />
                    <VariantResults :rows v-slot="result"><CheckboxGroup v-model="selected">
                        <Checkbox v-for="row in result.rows" :key="row._id" :value="row._id" :label="[row.label, row.sku].filter(Boolean).join(' · ')" />
                    </CheckboxGroup></VariantResults>
                    <p role="status">{{ __(':count variants selected', { count: selected.length }) }}</p>
                </fieldset>
                <Field :label="__('Change field')"><Select v-model="field" :options="Object.entries(fields).map(([value, label]) => ({ value, label: __(label) }))" /></Field>
                <Field v-if="field !== 'weight_kg'" :label="__('Currency')"><Select v-model="currency" :options="currencies.map(code => ({ value: code, label: code }))" /></Field>
                <Field :label="__('New value')"><Input v-model="amount" inputmode="decimal" /></Field>
                <div v-if="validAmount && selected.length" aria-live="polite">
                    <strong>{{ __('Preview changes') }}</strong>
                    <ul><li v-for="row in rows.filter(row => selected.includes(row._id)).slice(0, 5)" :key="row._id">
                        {{ row.sku }} · {{ field === 'weight_kg' ? (row[field] ?? '—') : (row[field]?.[currency] ?? '—') }}
                        → {{ amount }} {{ field === 'weight_kg' ? 'kg' : currency }}
                    </li></ul>
                    <small v-if="selected.length > 5">{{ __('Showing 5 of :count variants', { count: selected.length }) }}</small>
                </div>
            </div>
            <template #footer-end>
                <Button :text="__('Cancel')" @click="mode = ''" />
                <Button v-if="mode === 'generate'" variant="primary" :text="__('Add :count variants', { count: plan.additions.length })" :disabled="!plan.additions.length" @click="generate" />
                <Button v-else variant="primary" :text="__('Update :count variants', { count: selected.length })" :disabled="!validAmount || !selected.length" @click="applyBulk" />
            </template>
        </Stack>
    </div>
</template>

<style scoped>
.variant-summary { display: grid; font-size: .875rem; }
.variant-summary > div { display: flex; justify-content: space-between; align-items: baseline; gap: .75rem; padding: .45rem .1rem; border-bottom: 1px solid var(--theme-color-content-border); }
.variant-summary dt, .variant-summary dd { min-width: 0; overflow-wrap: anywhere; }
.variant-summary small, .variant-controls span { opacity: .75; }
.variant-summary dd { text-align: end; font-variant-numeric: tabular-nums; }
.variant-controls { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }
</style>
