<script setup>
import { computed } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Field, Input } from '@statamic/cms/ui';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);
const currencies = computed(() => [...new Set([...(props.meta?.currencies || []), ...Object.keys(props.value || {})])]);
</script>
<template>
    <div class="price-fields">
        <Field v-for="(currency, index) in currencies" :key="currency" :id="index ? id + '-' + currency : id" :label="currency">
            <Input :id="index ? id + '-' + currency : id" inputmode="decimal" :read-only="isReadOnly" :model-value="value?.[currency] ?? ''"
                :aria-label="__(config.display || 'Prices') + ' · ' + currency"
                @focus="emit('focus')" @blur="emit('blur')" @update:model-value="update({ ...value, [currency]: $event })" />
        </Field>
    </div>
</template>
<style scoped>
.price-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 8rem), 1fr)); gap: .65rem; }
</style>
