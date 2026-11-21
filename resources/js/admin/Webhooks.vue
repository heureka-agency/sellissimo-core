<script setup>
import { ref } from 'vue';
import { router, useForm } from '@statamic/cms/inertia';
import { Alert, Badge, Button, CardPanel, Checkbox, CheckboxGroup, Field, Input, Modal, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import SettingsHeader from './SettingsHeader.vue';
import SettingsErrors from './SettingsErrors.vue';
import QueuePagination from './QueuePagination.vue';
import { useSettingsChanges } from './useSettingsChanges';
const props = defineProps({ endpoints: Array, topics: Array, records: Object, secret: String, saveUrl: String, settingsUrl: String });
const open = ref(false);
const defaults = () => ({ id: null, revision: 0, name: '', url: '', enabled: true, topics: [], rotate: false });
const form = useForm(defaults());
function edit(endpoint) { form.clearErrors(); Object.assign(form, defaults(), endpoint); form.defaults(); open.value = true; }
const changes = useSettingsChanges(() => open.value && form.isDirty);
function setOpen(value) {
    if (value || changes.mayClose()) open.value = value;
}
function save() {
    changes.submit(() => form.post(props.saveUrl, { preserveScroll: true, onSuccess: () => { open.value = false; } }));
}
</script>
<template>
    <main class="ss-page ss-settings">
        <SettingsHeader :title="__('Webhooks')" icon="link" :parent-url="settingsUrl" :parent-title="__('Integrations and system status')" :scope="__('All sites')">
            <Button variant="primary" :text="__('Add endpoint')" @click="edit()" />
        </SettingsHeader>
        <Alert v-if="secret" variant="warning"><strong>{{ __('Signing secret') }}</strong><p>{{ __('Copy this secret to the receiving system before leaving this page.') }}</p><code class="secret">{{ secret }}</code></Alert>
        <CardPanel class="ss-settings-block" :heading="__('Endpoints')" :level="2">
            <p v-if="!endpoints.length">{{ __('Add an endpoint to send order events to another system.') }}</p>
            <ul v-else class="endpoint-list"><li v-for="hook in endpoints" :key="hook.id">
                <div><Button variant="ghost" :text="hook.name" @click="edit(hook)" /><small>{{ hook.url }}</small></div>
                <Badge :color="hook.enabled ? 'green' : 'default'" :text="hook.enabled ? __('Enabled') : __('Paused')" />
            </li></ul>
        </CardPanel>
        <CardPanel class="ss-settings-block" :heading="__('Delivery history')" :level="2">
            <p v-if="!records.data.length">{{ __('Deliveries appear when an order triggers a subscribed event.') }}</p>
            <div v-else class="ss-table-wrap"><Table>
                <TableColumns><TableColumn>{{ __('Events') }}</TableColumn><TableColumn>{{ __('Status') }}</TableColumn><TableColumn>{{ __('Actions') }}</TableColumn></TableColumns>
                <TableRows><TableRow v-for="row in records.data" :key="row.id">
                    <TableCell><strong>{{ row.topic }}</strong><small>{{ row.name }} · {{ row.created_at }}</small></TableCell>
                    <TableCell>{{ __(row.status) }}<small>{{ __('Attempts') }} {{ row.attempts }} {{ row.error }}</small></TableCell>
                    <TableCell><Button v-if="row.retryUrl" size="sm" :text="__('Retry')" @click="router.post(row.retryUrl)" /></TableCell>
                </TableRow></TableRows>
            </Table></div>
        </CardPanel>
        <QueuePagination v-if="records.last_page > 1" :records />
        <Modal :open @update:open="setOpen" :title="form.id ? __('Edit endpoint') : __('Add endpoint')">
            <form id="ss-endpoint" class="ss-form" @submit.prevent="save">
                <SettingsErrors :errors="form.errors" />
                <Field :label="__('Name')" :error="form.errors.name"><Input v-model="form.name" required /></Field>
                <Field :label="__('Delivery URL')" :error="form.errors.url"><Input v-model="form.url" type="url" :read-only="!!form.id" required /></Field>
                <Field :label="__('Events')" :error="form.errors.topics"><CheckboxGroup v-model="form.topics">
                    <Checkbox v-for="topic in topics" :key="topic" :value="topic" :label="topic" />
                </CheckboxGroup></Field>
                <Checkbox v-model="form.enabled" :label="__('Enable delivery')" />
                <Checkbox v-if="form.id" v-model="form.rotate" :label="__('Generate a new signing secret')" />
            </form>
            <template #footer><Button :text="__('Cancel')" @click="setOpen(false)" /><Button form="ss-endpoint" type="submit" variant="primary" :text="__('Save endpoint')" :disabled="form.processing" /></template>
        </Modal>
    </main>
</template>
<style scoped>
.endpoint-list li { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-block: .5rem; }
.endpoint-list li + li { border-top: 1px solid var(--theme-color-content-border); }
.endpoint-list small, .secret { overflow-wrap: anywhere; }
</style>
