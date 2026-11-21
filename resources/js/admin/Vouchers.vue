<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Card, Field, Icon, Input, Modal, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import OperationsPage from './OperationsPage.vue';
import QueuePagination from './QueuePagination.vue';
import SiteFilter from './SiteFilter.vue';

const props = defineProps({
    records: Object, site: String, sites: Object, currency: String, enabled: Boolean,
    requestId: String, listUrl: String, issueUrl: String, activity: Object, selected: String,
});
const movements = {
    issue: 'Issued', hold: 'Reserved for checkout', redeem: 'Payment confirmed', release: 'Reservation released',
    refund: 'Refunded to gift voucher', purchase_refund: 'Purchase refund reserved', purchase_restored: 'Purchase refund reversed',
    external_refund: 'Provider refund', external_restored: 'Provider refund reversed',
};
const issuing = ref(false);
const form = useForm({ amount: '', recipient: '' });
function issue() {
    form.transform(data => ({ ...data, site: props.site, request_id: props.requestId })).post(props.issueUrl, {
        preserveScroll: true,
        onSuccess: () => { form.reset(); issuing.value = false; },
    });
}
function viewActivity(id) {
    router.get(props.listUrl, { site: props.site, voucher: id, page: props.records.current_page }, {
        preserveScroll: true,
        onSuccess: () => document.getElementById('ss-voucher-activity')?.focus(),
    });
}
</script>

<template>
    <OperationsPage :title="__('Gift vouchers')" icon="money-cashier-calculator" :records>
        <template #action>
            <SiteFilter :sites :model-value="site" @update:model-value="site => router.get(listUrl, { site })" />
            <Button v-if="enabled" variant="primary" :text="__('Issue a gift voucher')" @click="form.clearErrors(); issuing = true" />
        </template>
        <Alert v-if="!enabled" :live="false" :text="__('Gift vouchers are disabled for this site.')" />
        <Card inset>
            <div v-if="!records.data.length" class="ss-empty">
                <Icon name="money-cashier-calculator" /><h2>{{ __('No vouchers issued yet.') }}</h2>
            </div>
            <div v-else class="ss-table-wrap">
                <Table class="ss-table">
                    <TableColumns><TableColumn>{{ __('Ending') }}</TableColumn><TableColumn>{{ __('Recipient') }}</TableColumn>
                        <TableColumn class="ss-number">{{ __('Issued') }}</TableColumn><TableColumn class="ss-number">{{ __('Available balance') }}</TableColumn>
                        <TableColumn>{{ __('Delivery') }}</TableColumn></TableColumns>
                    <TableRows><TableRow v-for="row in records.data" :key="row.id">
                        <TableCell><Button size="sm" variant="ghost" :text="row.ending" :aria-label="__('View activity for voucher ending in :ending', { ending: row.ending })"
                            @click="viewActivity(row.id)" /></TableCell>
                        <TableCell>{{ row.recipient }}</TableCell><TableCell class="ss-number">{{ row.face }}</TableCell><TableCell class="ss-number">{{ row.balance }}</TableCell>
                        <TableCell><Badge :text="row.sent ? __('Sent') : __('Pending')" :color="row.sent ? 'green' : 'amber'" /></TableCell>
                    </TableRow></TableRows>
                </Table>
            </div>
        </Card>
        <Card v-if="activity">
            <h2 id="ss-voucher-activity" tabindex="-1">{{ __('Voucher activity') }}</h2>
            <ul class="ss-activity"><li v-for="row in activity.data" :key="row.id"><small><time>{{ row.when }}</time> · {{ row.reference }}</small>
                {{ __(movements[row.kind] || row.kind) }}<strong v-if="row.kind !== 'redeem'"> · {{ row.amount }}</strong>
                <span v-if="row.actor"> · {{ row.actor }}</span> <Link v-if="row.orderUrl" :href="row.orderUrl">{{ __('View order') }}</Link></li></ul>
            <QueuePagination v-if="activity.last_page > 1" :records="activity" />
        </Card>
        <Modal v-if="enabled" v-model:open="issuing" :title="__('Issue a gift voucher')">
            <form id="ss-issue-voucher" class="ss-form" @submit.prevent="issue">
                <Field id="ss-voucher-recipient" :label="__('Recipient email')" :error="form.errors.recipient">
                    <Input id="ss-voucher-recipient" v-model="form.recipient" type="email" required maxlength="254" />
                </Field>
                <Field id="ss-voucher-amount" :label="__('Amount') + ' (' + currency + ')'" :error="form.errors.amount">
                    <Input id="ss-voucher-amount" v-model="form.amount" inputmode="decimal" required />
                </Field>
                <p v-for="(message, key) in form.errors" :key="key" v-show="!['recipient', 'amount'].includes(key)" role="alert">{{ message }}</p>
            </form>
            <template #footer><div class="ss-dialog-footer ss-actions"><Button :text="__('Cancel')" @click="issuing = false" />
                <Button type="submit" form="ss-issue-voucher" variant="primary" :disabled="form.processing" :text="__('Issue and email voucher')" /></div></template>
        </Modal>
    </OperationsPage>
</template>
