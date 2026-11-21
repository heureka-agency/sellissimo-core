<script setup>
import { computed } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Button, Input, injectPublishContext } from '@statamic/cms/ui';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update, isReadOnly, fieldPathKeys } = Fieldtype.use(emit, props);
defineExpose(expose);
const context = injectPublishContext();
const conflict = computed(() => {
    const links = context?.errors?.value?.[fieldPathKeys.value.join('.') + '.conflict_entry'];
    return Array.isArray(links) ? links[0] : links;
});
</script>
<template>
    <div class="sku-field"><Input :id="id" :model-value="value" :read-only="isReadOnly" @focus="emit('focus')" @blur="emit('blur')" @update:model-value="update" />
    <Button v-if="meta.stockUrl && String(value || '').trim().toUpperCase() === meta.stockSku" size="sm" variant="ghost" :href="meta.stockUrl" target="_blank" rel="noreferrer" :text="__('Adjust stock')" />
    <Button v-if="conflict" size="sm" :href="conflict" target="_blank" rel="noreferrer" :text="__('Open conflicting entry')" /></div>
</template>

<style scoped>
.sku-field { display: grid; gap: .3rem; }
.sku-field a { justify-self: start; }
</style>
