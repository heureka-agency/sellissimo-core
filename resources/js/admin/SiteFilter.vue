<script setup>
import { computed, useId } from 'vue';
import { Select } from '@statamic/cms/ui';

const props = defineProps({ sites: Object, modelValue: String, allowAll: Boolean });
const emit = defineEmits(['update:modelValue']);
const id = useId();
const options = computed(() => {
    const choices = Object.entries(props.sites || {}).map(([value, label]) => ({ value, label }));
    // The empty option needs a nonempty value in Statamic’s Select.
    return props.allowAll ? [{ value: '/', label: __('All sites') }, ...choices] : choices;
});
</script>

<template>
    <div v-if="Object.keys(sites || {}).length > 1" class="ss-filter">
        <label :for="id">{{ __('Site') }}</label>
        <Select :id :model-value="modelValue || (allowAll ? '/' : null)" :options option-value="value" option-label="label"
            @update:model-value="value => emit('update:modelValue', value === '/' ? '' : value)" />
    </div>
</template>
