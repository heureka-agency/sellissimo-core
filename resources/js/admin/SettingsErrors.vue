<script setup>
import { nextTick, ref, watch } from 'vue';
import { Alert, Button } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';

const props = defineProps({ errors: Object });
const summary = ref(null);
watch(() => props.errors, async errors => {
    if (Object.keys(errors || {}).length) { await nextTick(); summary.value?.focus(); }
}, { deep: true, immediate: true });
</script>
<template>
    <div v-if="Object.keys(errors || {}).length" ref="summary" tabindex="-1"><Alert variant="error" live>
        <ul><li v-for="(message, field) in errors" :key="field">{{ [message].flat().join(' ') }}</li></ul>
        <Button v-if="errors.revision" size="sm" :text="__('Reload latest settings')" @click="router.visit(window.location.href)" />
    </Alert></div>
</template>
