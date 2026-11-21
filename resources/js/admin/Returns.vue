<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@statamic/cms/inertia';
import { Badge, Button, Card, Icon, Input, Modal, Select, Stack, StackHeader, StackContent, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import SiteFilter from './SiteFilter.vue';
import OperationsPage from './OperationsPage.vue';
import ReturnCase from './ReturnCase.vue';
import { returnActions } from './returnActions';

const props = defineProps({ selectedCase: String, records: Object, locations: Object, filters: Object, listUrl: String, sites: Object, policySite: String, policyDays: Number, policyUrl: String });
const policy = useForm({ site: props.policySite, days: props.policyDays });
const statusOptions = [{ value: '/', label: __('All requests') }, ...['requested', 'approved', 'declined', 'received', 'exchanged']
    .map(value => ({ value, label: __(value.charAt(0).toUpperCase() + value.slice(1)) }))];
const editingPolicy = ref(false);
function editPolicy() {
    policy.days = props.policyDays;
    policy.clearErrors();
    editingPolicy.value = true;
}
const current = computed(() => props.records.data.find(row => row.id === props.selectedCase));
const panelOpen = ref(!!current.value);
const content = ref(null);
const details = ref(null);
const caseTitle = computed(() => current.value ? __('Return request') + ' · ' + current.value.orderNumber : __('Return request'));
const narrow = window.matchMedia('(max-width: 640px)');
const mobile = ref(narrow.matches);
const resized = () => mobile.value = narrow.matches;
let triggerId = props.selectedCase ? 'ss-return-' + props.selectedCase : '';
const requestedAction = ref(null);
watch(current, value => { panelOpen.value = !!value; });
onMounted(() => narrow.addEventListener('change', resized));
onBeforeUnmount(() => narrow.removeEventListener('change', resized));
function navigate(changes, keepState = false) {
    const params = { ...props.filters, page: props.records.current_page, ...changes };
    router.get(props.listUrl, Object.fromEntries(Object.entries(params).filter(([, value]) => value !== null && value !== '')), { preserveScroll: true, preserveState: keepState, replace: keepState });
}
function showCase(row, next = false) {
    triggerId = (next ? 'ss-return-action-' : 'ss-return-') + row.id;
    requestedAction.value = next ? returnActions(row).find(action => action.primary)?.key : null;
    navigate({ case: row.id }, true);
}
function closeCase() {
    panelOpen.value = false;
    if (props.selectedCase) navigate({ case: null }, true);
}
function restoreFocus() {
    nextTick(() => {
        const button = document.getElementById(triggerId);
        (button || content.value)?.focus({ preventScroll: true });
    });
}
function nextAction(row) {
    return returnActions(row).find(action => action.primary)?.label || __('View request');
}
</script>
<template>
    <OperationsPage :title="__('Return requests')" icon="package-box-crate" :records>
        <template #action><Button v-if="policyUrl" :text="__('Return policy')" @click="editPolicy" /></template>
        <Modal v-if="policyUrl" v-model:open="editingPolicy" :title="__('Return policy')">
            <form class="ss-form" @submit.prevent="policy.post(policyUrl, { preserveScroll: true, onSuccess: () => editingPolicy = false })">
                <p v-if="Object.keys(sites).length > 1">{{ sites[policySite] }}</p>
                <label>{{ __('Days after delivery') }}<Input v-model="policy.days" type="number" min="1" max="3650" /></label>
                <small>{{ __('Leave blank for unlimited returns. Changes apply to new purchases.') }}</small>
                <p role="alert" v-if="policy.errors.days">{{ policy.errors.days }}</p>
                <div class="ss-actions">
                    <Button type="button" :text="__('Cancel')" @click="editingPolicy = false" />
                    <Button variant="primary" type="submit" :disabled="policy.processing" :text="__('Save return policy')" />
                </div>
            </form>
        </Modal>
        <Card inset>
            <div ref="content" class="ss-toolbar" tabindex="-1">
                <SiteFilter :sites :model-value="policySite" @update:model-value="site => navigate({ site, page: null, order: null, case: null })" />
                <div class="ss-filter"><label for="ss-return-status">{{ __('Status') }}</label>
                    <Select id="ss-return-status" :model-value="filters.status || '/'" :options="statusOptions" option-value="value" option-label="label"
                        @update:model-value="status => navigate({ status: status === '/' ? null : status, page: null, case: null })" />
                </div>
                <Button v-if="filters.order || filters.status" size="sm" :text="__('Clear filters')" @click="navigate({ order: null, status: null, page: null, case: null })" />
            </div>
            <div v-if="records.data.length" class="ss-table-wrap ss-return-queue">
                <Table class="ss-table">
                    <TableColumns><TableColumn>{{ __('Order') }}</TableColumn><TableColumn>{{ __('Item') }}</TableColumn><TableColumn>{{ __('Requested') }}</TableColumn><TableColumn>{{ __('Status') }}</TableColumn><TableColumn>{{ __('Next action') }}</TableColumn></TableColumns>
                    <TableRows><TableRow v-for="row in records.data" :key="row.id">
                        <TableCell><button type="button" :id="'ss-return-' + row.id" class="ss-case-trigger" :aria-label="__('View request') + ' · ' + row.orderNumber + ' · ' + row.title" @click="showCase(row)">{{ row.orderNumber }}</button></TableCell>
                        <TableCell>{{ row.quantity }} × {{ row.title }}<small>{{ row.email }}</small></TableCell>
                        <TableCell :data-label="__('Requested')">{{ row.requestedDate || '—' }}</TableCell>
                        <TableCell><Badge :text="__(row.status.charAt(0).toUpperCase() + row.status.slice(1))" /></TableCell>
                        <TableCell><Button :id="'ss-return-action-' + row.id" size="sm" :text="nextAction(row)" @click="showCase(row, true)" /></TableCell>
                    </TableRow></TableRows>
                </Table>
            </div>
            <div v-else class="ss-empty"><Icon name="package-box-crate" /><h2>{{ filters.status || filters.order ? __('No matching results') : __('No return requests yet') }}</h2></div>
        </Card>
        <Stack :open="panelOpen" :size="mobile ? 'full' : 'half'" :wrap-slot="false" :show-close-button="false"
            @update:open="value => { if (!value) closeCase() }" @opened="details?.focusInitial()" @closed="restoreFocus">
            <section role="dialog" aria-modal="true" :aria-label="caseTitle" class="ss-return-dialog">
                <StackHeader :title="caseTitle" :show-close-button="false">
                    <template #actions="{ close }"><Button icon="x" variant="ghost" :aria-label="__('Close')" @click="close" /></template>
                </StackHeader>
                <StackContent><ReturnCase v-if="current" ref="details" :row="current" :initial-action="requestedAction" :locations /></StackContent>
            </section>
        </Stack>
    </OperationsPage>
</template>
