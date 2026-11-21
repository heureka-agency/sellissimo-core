<script setup>
import { computed, ref } from 'vue';
import { Alert, Button, Field, Icon } from '@statamic/cms/ui';
import { Fieldtype } from '@statamic/cms';
import StoreChoice from './StoreChoice.vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);
const files = computed(() => Array.isArray(props.value) ? props.value : props.value ? [props.value] : []);
const uploads = ref([]);
const uploading = ref(false);
const uploadError = ref('');
async function uploadFile(event) {
    const file = event.target.files[0];
    if (!file || uploading.value) return;
    const data = new FormData();
    data.append('file', file);
    uploading.value = true;
    uploadError.value = '';
    try {
        const response = await fetch(props.meta.uploadUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': props.meta.csrfToken, Accept: 'application/json' }, body: data });
        const result = await response.json();
        if (!response.ok) throw new Error(result.errors?.file?.[0] || result.message || __('The file could not be uploaded.'));
        uploads.value.push(result);
        addFile(result.value);
    } catch (error) {
        uploadError.value = error.message || __('The file could not be uploaded.');
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
}
function addFile(file) {
    if (file && !files.value.includes(file)) update([...files.value, file]);
}
</script>
<template>
    <div v-if="config.source === 'download'" class="ss-stack">
        <ul v-if="files.length" class="private-files"><li v-for="path in files" :key="path"><span><Icon name="download-arrow-down" aria-hidden="true" />{{ path.split('/').pop() }}</span>
            <Button v-if="!isReadOnly" size="sm" variant="ghost" :text="__('Remove')" :aria-label="__('Remove ') + path" @click="update(files.filter(f => f !== path))" />
        </li></ul>
        <StoreChoice v-if="!isReadOnly" :key="files.join('|')" :id :model-value="null"
            :options="[...(meta.options || []), ...uploads].filter(file => !files.includes(file.value))" :placeholder="__('Add a private file')" @update:model-value="addFile" />
        <Field v-if="meta.uploadUrl && !isReadOnly" :id="id + '-file'" :label="__('Upload a private file')">
            <input :id="id + '-file'" type="file" :disabled="uploading" @change="uploadFile" />
        </Field>
        <small v-if="uploading" role="status">{{ __('Uploading…') }}</small>
        <Alert v-if="uploadError" variant="error" live>{{ uploadError }}</Alert>
    </div>
    <StoreChoice v-else
        :id
        :model-value="value"
        :options="meta.options || []"
        :clearable="config.source !== 'tax_class'"
        :read-only="isReadOnly"
        :placeholder="config.placeholder"
        @update:model-value="update"
    />
    <p class="ss-field-help" v-if="meta.message || (config.source === 'download' && !meta.uploadUrl && !meta.options?.length)">
        {{ meta.message || __('Add a file to the store’s private download disk to make it available here.') }}
    </p>
</template>

<style scoped>
.private-files li, .private-files span { display: flex; align-items: center; gap: .5rem; }
.private-files li { justify-content: space-between; padding-block: .3rem; }
.private-files span { min-width: 0; overflow-wrap: anywhere; }
.private-files svg { width: 1rem; height: 1rem; flex: none; }
</style>
