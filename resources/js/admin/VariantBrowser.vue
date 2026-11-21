<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Field, Input, Modal, PublishFieldsProvider, PublishFields, Stack } from '@statamic/cms/ui';
import { variantRowId } from './variantRowId';
import VariantResults from './VariantResults.vue';

const props = defineProps({ rows: Array, config: Object, meta: Object, readOnly: Boolean, fieldPath: String, metaPath: String, errors: Object });
const emit = defineEmits(['update', 'metadata']);
const current = ref(null);
const deleting = ref(false);
const position = ref(null);
const index = computed(() => props.rows.findIndex(row => row._id === current.value));
const row = computed(() => props.rows[index.value]);
const capacity = computed(() => !props.readOnly && (!props.config.max_rows || props.rows.length < props.config.max_rows));
const canRemove = computed(() => !props.readOnly && props.rows.length > (props.config.min_rows || 0));
watch(() => props.errors, errors => {
    for (const path of Object.keys(errors || {})) {
        if (!path.startsWith(props.fieldPath + '.')) continue;
        const key = path.slice(props.fieldPath.length + 1).split('.')[0];
        if (/^\d+$/.test(key) && props.rows[Number(key)]) { current.value = props.rows[Number(key)]._id; break; }
    }
}, { immediate: true });
watch(index, value => { position.value = value + 1; }, { immediate: true });
function add(original = null) {
    if (!capacity.value) return;
    const value = JSON.parse(JSON.stringify(original || props.meta.defaults));
    const metadata = JSON.parse(JSON.stringify(original ? props.meta.existing[original._id] : props.meta.new));
    // Nested Statamic Grid, Replicator and Bard fields key their metadata by each row or set ID.
    const ids = new Map();
    function newIds(item) {
        if (!item || typeof item !== 'object') return;
        if (item._id) { const id = variantRowId(); ids.set(item._id, id); item._id = id; }
        if (item.type === 'set' && item.attrs?.id) { const id = variantRowId(); ids.set(item.attrs.id, id); item.attrs.id = id; }
        Object.values(item).forEach(newIds);
    }
    function remapMeta(item) {
        if (Array.isArray(item)) return item.map(remapMeta);
        if (!item || typeof item !== 'object') return ids.get(item) ?? item;
        return Object.fromEntries(Object.entries(item).map(([key, value]) => [ids.get(key) ?? key, remapMeta(value)]));
    }
    if (original) newIds(value);
    value._id ||= variantRowId();
    emit('metadata', { ...props.meta, existing: { ...props.meta.existing, [value._id]: remapMeta(metadata) } });
    emit('update', [...props.rows, value]);
    current.value = value._id;
}
function remove() {
    if (!canRemove.value || !row.value) return;
    const id = current.value;
    deleting.value = false;
    current.value = null;
    const existing = { ...props.meta.existing };
    delete existing[id];
    emit('metadata', { ...props.meta, existing });
    emit('update', props.rows.filter(item => item._id !== id));
}
function move() {
    const target = Number(position.value) - 1;
    if (props.readOnly || !props.config.reorderable || !row.value || !Number.isInteger(target) || target < 0 || target >= props.rows.length) return;
    const ordered = [...props.rows];
    ordered.splice(target, 0, ...ordered.splice(index.value, 1));
    emit('update', ordered);
}
function prices(row) {
    return Object.entries(row.prices || {}).filter(([, value]) => value !== '' && value != null).map(([code, value]) => code + ' ' + value).join(' · ');
}
</script>
<template>
    <VariantResults :rows v-slot="result">
        <ul class="variant-results"><li v-for="item in result.rows" :key="item._id">
            <Button type="button" size="sm" variant="ghost" :text="item.label || item.sku || __('Untitled')" @click="current = item._id" />
            <small v-if="item.label && item.sku">{{ item.sku }}</small><span>{{ prices(item) || __('No price') }}</span>
        </li></ul>
    </VariantResults>
    <div v-if="capacity"><Button type="button" size="sm" :text="__(config.add_row || 'Add variant')" @click="add()" /></div>
    <Stack :open="!!row" @update:open="value => { if (!value) current = null }" size="half" :title="row?.label || row?.sku || __('Variant details')">
        <template v-if="row">
            <PublishFieldsProvider :key="row._id" :fields="config.fields" :as-config="false" :read-only="readOnly"
                :field-path-prefix="fieldPath + '.' + index" :meta-path-prefix="metaPath + '.existing.' + row._id">
                <PublishFields />
            </PublishFieldsProvider>
            <div v-if="config.reorderable && !readOnly" class="variant-position">
                <Field :label="__('Position')"><Input type="number" v-model="position" min="1" :max="rows.length" /></Field>
                <Button type="button" size="sm" :text="__('Move')" :disabled="Number(position) === index + 1" @click="move" />
            </div>
        </template>
        <template #footer-start>
            <Button v-if="canRemove" type="button" size="sm" variant="ghost" :text="__('Delete Row')" @click="deleting = true" />
            <Button v-if="capacity" type="button" size="sm" :text="__('Duplicate Row')" @click="add(row)" />
        </template>
        <template #footer-end><Button :text="__('Done')" @click="current = null" /></template>
    </Stack>
    <Modal v-model:open="deleting" :title="__('Delete Row')">
        <p>{{ __('Remove :variant from this product?', { variant: row?.label || row?.sku }) }}</p>
        <template #footer><Button :text="__('Cancel')" @click="deleting = false" /><Button variant="danger" :text="__('Remove')" @click="remove" /></template>
    </Modal>
</template>
<style scoped>
.variant-results { display: grid; }
.variant-results li { display: flex; align-items: baseline; flex-wrap: wrap; gap: .35rem .75rem; padding-block: .3rem; border-bottom: 1px solid var(--theme-color-content-border); }
.variant-results li > * { min-width: 0; overflow-wrap: anywhere; }
.variant-results small { opacity: .75; }
.variant-results span { margin-inline-start: auto; font-variant-numeric: tabular-nums; }
.variant-position { display: flex; align-items: end; gap: .5rem; padding: 1rem; }
</style>
