<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, useForm } from '@statamic/cms/inertia';
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, Field, Heading, Input, Select, Textarea } from '@statamic/cms/ui';
import { returnActions } from './returnActions';

const props = defineProps({ row: Object, locations: Object, initialAction: String });
const editorTitle = ref(null);
const toolbar = ref(null);
const actions = computed(() => returnActions(props.row));
const primary = computed(() => actions.value.find(action => action.primary));
const secondary = computed(() => actions.value.filter(action => action !== primary.value));
const locationOptions = computed(() => Object.entries(props.locations).map(([value, label]) => ({ value, label })));
const origins = computed(() => locationOptions.value.filter(option => !Object.keys(props.row.reservedAt).length || option.value in props.row.reservedAt)
    .map(option => ({ ...option, label: props.row.reservedAt[option.value] ? option.label + ' · ' + props.row.reservedAt[option.value] : option.label })));
const refund = useForm({ request_id: '', amount: 0, manual: false, reference: '', reason: '' });
const settlement = useForm({ reference: '', gateway: '' });
const open = ref(null);
const form = useForm({ action: '', message: '', origin: '', quantity: null, restocked: 0, location: '', tracking: '', inbound_label: null, inbound_label_url: '', inbound_tracking_url: '', inbound_tracking: '', replacement: '', operation_id: '' });
function remaining(row) {
    return row.quantity - row.parcels.reduce((sum, parcel) => sum + Number(parcel.quantity), 0);
}
function setOrigin(row, code) {
    form.origin = code;
    form.quantity = code ? row.reservedAt[code] ?? remaining(row) : null;
}
function begin(row, action) {
    open.value = action;
    form.reset();
    form.clearErrors();
    form.action = action;
    form.inbound_label_url = row.inbound_label_url || '';
    form.inbound_tracking_url = row.inbound_tracking_url || '';
    form.inbound_tracking = row.inbound_tracking || '';
    form.operation_id = row.operationId;
    if (action === 'dispatch') {
        const choices = Object.keys(row.reservedAt).length ? Object.keys(row.reservedAt) : Object.keys(props.locations);
        setOrigin(row, choices.length === 1 ? choices[0] : '');
    }
    if (action === 'refund') {
        refund.reset();
        refund.clearErrors();
        refund.request_id = row.refundRequestId;
        refund.amount = row.refund.due;
        refund.manual = !row.refundableOnline;
    }
    if (action === 'balance') {
        settlement.reset();
        settlement.clearErrors();
        settlement.gateway = row.balanceGateways?.[0] || '';
    }
    nextTick(() => editorTitle.value?.focus());
    form.restocked = 0;
    form.location = Object.keys(props.locations).length === 1 ? Object.keys(props.locations)[0] : '';
}
const active = computed(() => actions.value.find(action => action.key === open.value));
const currentForm = computed(() => open.value === 'refund' ? refund : open.value === 'balance' ? settlement : form);
function focusInitial() {
    (editorTitle.value || toolbar.value)?.focus({ preventScroll: true });
}
defineExpose({ focusInitial });
function finish() {
    open.value = null;
    nextTick(() => toolbar.value?.focus());
}
function submit(row) {
    const url = open.value === 'refund' ? row.refundUrl : open.value === 'balance' ? row.balanceUrl : row.actionUrl;
    currentForm.value.post(url, { preserveScroll: true, onSuccess: finish, onError: () => nextTick(() => editorTitle.value?.focus()) });
}
watch(() => props.row.id, () => {
    open.value = null;
    if (actions.value.some(action => action.key === props.initialAction)) begin(props.row, props.initialAction);
}, { immediate: true });
</script>
<template>
    <section class="ss-case-content">
        <div ref="toolbar" tabindex="-1" class="ss-case-header">
            <Heading :text="row.quantity + ' × ' + row.title" />
            <Badge :text="__(row.status.charAt(0).toUpperCase() + row.status.slice(1))" />
        </div>
        <p>{{ row.email }} · {{ row.requestedDate }}
            <Link v-if="row.orderUrl" class="ss-order-link" :href="row.orderUrl">{{ __('View order') }} {{ row.orderNumber }}</Link>
        </p>
        <div>
            <Heading :text="__('Reason')" />
            <p>{{ row.reason }}</p>
        </div>
        <div v-if="!active" class="ss-actions">
            <Button v-if="primary" variant="primary" :text="primary.label" @click="begin(row, primary.key)" />
            <Dropdown v-if="secondary.length">
                <template #trigger><Button :text="__('More actions')" /></template>
                <DropdownMenu><DropdownItem v-for="action in secondary" :key="action.key" :text="action.label" @click="begin(row, action.key)" /></DropdownMenu>
            </Dropdown>
        </div>
        <form v-else class="ss-form ss-case-editor" @submit.prevent="submit(row)">
            <h2 ref="editorTitle" tabindex="-1">{{ active.label }}</h2>
            <template v-if="open === 'refund'">
                <strong>{{ row.refund.dueFormatted }}</strong>
                <label v-if="row.refundableOnline"><input type="checkbox" v-model="refund.manual" /> {{ __('Record manual refund') }}</label>
                <template v-if="refund.manual">
                    <Field id="return-reference" :label="__('Reference')" :error="refund.errors.reference"><Input id="return-reference" v-model="refund.reference" required maxlength="150" /></Field>
                    <Field id="return-reason" :label="__('Reason')" :error="refund.errors.reason"><Input id="return-reason" v-model="refund.reason" required maxlength="250" /></Field>
                </template>
            </template>
            <template v-else-if="open === 'balance'">
                <strong>{{ row.balance.amount }}</strong>
                <Field v-if="row.balance.difference < 0" id="exchange-reference" :label="__('Offline refund reference')" :error="settlement.errors.reference">
                    <Input id="exchange-reference" v-model="settlement.reference" maxlength="150" :required="!row.canRefundBalance" />
                </Field>
                <Field v-else id="exchange-gateway" :label="__('Payment gateway')" :error="settlement.errors.gateway">
                    <Select id="exchange-gateway" v-model="settlement.gateway" :options="row.balanceGateways" />
                </Field>
            </template>
            <template v-else>
                <template v-if="form.action === 'shipping'">
                    <label>{{ __('Return label PDF') }}<input type="file" accept="application/pdf" @change="form.inbound_label = $event.target.files[0] || null" /></label>
                    <label>{{ __('Return label URL') }}<Input type="url" v-model="form.inbound_label_url" maxlength="4096" placeholder="https://" /></label>
                    <label>{{ __('Incoming tracking number') }}<Input v-model="form.inbound_tracking" maxlength="200" /></label>
                    <label>{{ __('Incoming tracking URL') }}<Input type="url" v-model="form.inbound_tracking_url" maxlength="4096" placeholder="https://" /></label>
                </template>
                <Field v-if="form.action === 'substitute'" id="return-replacement" :label="__('Replacement SKU')" :error="form.errors.replacement">
                    <Select id="return-replacement" v-model="form.replacement" :placeholder="__('Choose a replacement')" option-label="label" option-value="value"
                        :options="row.alternatives.map(option => ({ value: option.sku, label: [option.title, option.sku, option.price].join(' · ') }))" />
                </Field>
                <p v-if="form.action === 'convert'">{{ __('Release the replacement and review a refund once the return is received.') }}</p>
                <label>{{ __('Message to buyer') }}<Textarea v-model="form.message" :required="form.action === 'message'" maxlength="1000" /></label
                ><label v-if="form.action === 'receive' && row.tracked"
                    >{{ __('Units to restock') }}<Input
                        type="number"
                        min="0"
                        :max="row.quantity"
                        v-model="form.restocked"
                        required
                /></label>
                <Field v-if="form.action === 'receive' && Number(form.restocked) > 0" id="return-restock-at" :label="__('Restock at')" :error="form.errors.location">
                    <Select id="return-restock-at" v-model="form.location" :placeholder="__('Choose a receiving location.')" :options="locationOptions" option-label="label" option-value="value" />
                </Field>
                <template v-if="form.action === 'dispatch'">
                    <Field id="return-origin" :label="__('Dispatch location')" :error="form.errors.origin">
                        <Select id="return-origin" :model-value="form.origin" :options="origins" :placeholder="__('Choose a dispatch location')" option-label="label" option-value="value" @update:model-value="setOrigin(row, $event)" />
                    </Field>
                    <label>{{ __('Quantity') }}<Input type="number" min="1" :max="row.reservedAt[form.origin] ?? remaining(row)" v-model="form.quantity" required /></label>
                </template>
                <label v-if="form.action === 'dispatch'"
                    >{{ __('Replacement tracking') }}<Input v-model="form.tracking" required
                /></label>
            </template>
            <ul v-if="Object.keys(currentForm.errors).length" role="alert"><li v-for="(message, key) in currentForm.errors" :key="key">{{ message }}</li></ul>
            <div class="ss-actions"><Button type="button" :text="__('Cancel')" @click="finish" />
                <Button type="submit" :variant="['refund', 'decline'].includes(open) ? 'danger' : 'primary'" :disabled="currentForm.processing"
                    :text="open === 'refund' && refund.manual ? __('Record manual refund') : open === 'balance' ? (row.balance.difference > 0 ? __('Request payment') : __('Settle refund')) : active.label" />
            </div>
        </form>
            <p v-if="row.replacement">{{ __('Replacement SKU ·') }} {{ row.replacement }}</p>
            <div v-if="row.balance?.difference">
                <strong>{{ __(row.balance.difference > 0 ? 'Exchange amount due' : 'Exchange credit') }} · {{ row.balance.amount }}</strong>
                <p>{{ row.balance.label }}</p>
            </div>
            <p v-if="row.inbound_label_path">{{ __('Private return label attached') }}</p>
            <p v-if="row.inbound_label_url"><a :href="row.inbound_label_url" target="_blank" rel="noreferrer">{{ __('Return shipping label') }}</a></p>
            <p v-if="row.inbound_tracking || row.inbound_tracking_url">{{ __('Return parcel') }} ·
                <a v-if="row.inbound_tracking_url" :href="row.inbound_tracking_url" target="_blank" rel="noreferrer">{{ row.inbound_tracking || __('Track parcel') }}</a>
                <span v-else>{{ row.inbound_tracking }}</span></p>
            <p v-if="row.staff_message && !row.history?.some(event => event.message === row.staff_message)">{{ __('Store message ·') }} {{ row.staff_message }}</p>
            <ul v-if="row.parcels.length"><li v-for="parcel in row.parcels" :key="parcel.id">
                {{ parcel.quantity }} × {{ row.replacement }} <template v-if="parcel.origin"> · {{ locations[parcel.origin] || parcel.origin }}</template> · {{ parcel.tracking }}
            </li></ul>
            <p v-else-if="row.tracking">{{ __('Tracking ·') }} {{ row.tracking }}</p>
            <details v-if="row.history?.length"><summary>{{ __('Return history') }}</summary>
                <ol class="ss-activity"><li v-for="(event, index) in row.history" :key="index">
                    <small v-if="event.displayDate">{{ event.displayDate }}</small>
                    <p>{{ event.label }}<span v-if="event.replacement && event.action === 'substitute'"> · {{ event.replacement }}</span></p>
                    <p v-if="event.message">{{ event.message }}</p>
                </li></ol>
            </details>
            <div v-if="row.refund">
                <strong>{{ row.refund.label }}</strong>
                <p v-if="row.refund.due">{{ __('Amount due') }} · {{ row.refund.dueFormatted }}</p>
                <p v-if="row.refund.pending">{{ __('Pending') }} · {{ row.refund.pendingFormatted }}</p>
                <p v-if="row.refund.paid">{{ __('Refunded') }} · {{ row.refund.paidFormatted }}</p>
            </div>
    </section>
</template>
