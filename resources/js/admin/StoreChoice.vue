<script setup>
import { computed } from 'vue';
import { Combobox } from '@statamic/cms/ui';

const model = defineModel();
const props = defineProps({
    options: { type: Array, required: true },
    placeholder: String,
    clearable: Boolean,
    readOnly: Boolean,
    id: String,
});
const choices = computed(() => {
    if (!model.value || props.options.some((choice) => choice.value === model.value)) return props.options;
    return [{ value: model.value, label: __(':sku (unavailable)', { sku: model.value }) }, ...props.options];
});
</script>

<template>
    <Combobox
        :model-value="model || null"
        @update:model-value="model = $event"
        :id
        :options="choices"
        option-label="label"
        option-value="value"
        :search-keys="['label', 'value']"
        :placeholder
        :clearable
        :read-only="readOnly"
        searchable
    />
</template>
