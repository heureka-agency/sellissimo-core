<script setup>
import { Button, Input, Textarea } from '@statamic/cms/ui';
import { onBeforeUnmount, ref } from 'vue';
const values = defineModel({ type: Object, required: true });
const props = defineProps({ fields: Object, scope: String, uploadUrl: String, csrf: String, complete: Boolean });
const emit = defineEmits(['uploading']);
const error = ref('');
const uploads = new Map();
onBeforeUnmount(() => {
    for (const controller of uploads.values()) controller.abort();
    emit('uploading', false);
});
async function upload(event, handle) {
    const file = event.target.files[0];
    if (!file) return;
    if (!props.uploadUrl) {
        values.value = { ...values.value, [handle]: file };
        return;
    }
    uploads.get(handle)?.abort();
    const controller = new AbortController();
    uploads.set(handle, controller);
    emit('uploading', true);
    const data = new FormData(); data.append('type', props.scope); data.append('handle', handle); data.append('file', file);
    try {
        const res = await fetch(props.uploadUrl, { method: 'POST', signal: controller.signal, headers: { 'X-CSRF-TOKEN': props.csrf, Accept: 'application/json' }, body: data });
        if (!res.ok) throw new Error();
        values.value = { ...values.value, [handle]: (await res.json()).id };
        error.value = '';
    } catch {
        if (!controller.signal.aborted) {
            event.target.value = '';
            error.value = __('Upload failed. Choose a PDF or image up to 10 MB and try again.');
        }
    } finally {
        if (uploads.get(handle) === controller) uploads.delete(handle);
        emit('uploading', uploads.size > 0);
    }
}
</script>
<template>
    <div v-if="Object.keys(fields || {}).length" class="ss-field-grid">
        <label v-for="(field, handle) in fields" :key="handle">{{ field.label }}<span v-if="field.required && !field.internal"> *</span> <small v-if="field.internal">{{ __('Staff only') }}</small>
            <template v-if="field.type === 'assets'">
                <small v-if="values[handle]">{{ values[handle]?.name || __('File attached') }}</small>
                <Button size="sm" v-if="!uploadUrl && values[handle]" type="button" @click="values = { ...values, [handle]: null }">{{ __('Remove attachment') }}</Button>
                <input type="file" :required="complete && field.required && !field.internal && !values[handle]" accept=".pdf,.jpg,.jpeg,.webp,.png" @change="upload($event, handle)">
            </template>
            <select :required="complete && field.required && !field.internal" v-else-if="['select','radio'].includes(field.type)" v-model="values[handle]"><option value=""></option><option v-for="(label, key) in field.options" :key="key" :value="key">{{ label }}</option></select>
            <input v-else-if="field.type === 'toggle'" type="checkbox" v-model="values[handle]">
            <Textarea :required="complete && field.required && !field.internal" v-else-if="field.type === 'textarea'" v-model="values[handle]" />
            <Input v-else :required="complete && field.required && !field.internal" :type="{ date: 'date', integer: 'number' }[field.type] || 'text'" v-model="values[handle]" />
        </label>
        <small v-if="error" role="alert">{{ error }}</small>
    </div>
</template>
