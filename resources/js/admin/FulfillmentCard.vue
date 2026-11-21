<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@statamic/cms/inertia';
import { Badge, Button, Card, Icon, Input, Field, Select } from '@statamic/cms/ui';

const props = defineProps({ order: Object });
const carrierForm = useForm({ action: '', items: {}, request_id: '' });
function carrierAction(action) {
    const current = props.order.activeLabel;
    carrierForm.transform(() => action === 'void' ? { action, request_id: current.id } : {
        action, request_id: current?.id || ship.request_id, origin: current?.origin || ship.origin, items: current?.items || ship.items,
    }).post(props.order.actions.labels, { preserveScroll: true });
}
function dispatchLabel() {
    const current = props.order.activeLabel;
    ship.transform(() => ({ request_id: current.id, items: current.items, tracking: current.tracking, notify: ship.notify }))
        .post(props.order.actions.ship, { preserveScroll: true, onFinish: () => ship.transform(data => data) });
}
const operation = ref('');
const shipmentHeading = ref(null);
async function startShipment() {
    operation.value = 'ship';
    await nextTick();
    shipmentHeading.value?.focus();
}
defineExpose({ startShipment });
const pending = computed(() => props.order.lines.filter((line) => line.remaining > 0));
const returnable = computed(() => props.order.lines.filter((line) => line.returnable > 0));
const ship = useForm({ request_id: '', tracking: '', tracking_url: '', origin: '', items: {}, notify: false });
const receive = useForm({ request_id: '', line_key: '', quantity: 1, restocked: 0, location: '' });
const reassign = useForm({ request_id: '', reservation: 0, to: '', quantity: 1 });
const chosen = computed(() => props.order.stockLocations?.[reassign.reservation]);
const origins = computed(() => Object.entries(props.order.origins).map(([value, label]) => ({ value, label })));
const destinations = computed(() => Object.entries(props.order.origins).filter(([code]) => code !== chosen.value?.code));
function reassignStock() {
    const row = chosen.value;
    if (!row) return;
    reassign.transform(data => ({ request_id: data.request_id, sku: row.sku, from: row.code, to: data.to, quantity: data.quantity }))
        .post(props.order.actions.reassign, { preserveScroll: true, onSuccess: () => { operation.value = ''; } });
}
function reservedFor(line) {
    return props.order.stockLocations?.find(row => row.sku === line.sku && row.code === ship.origin)?.quantity || 0;
}
function fillItems() {
    const used = {};
    const items = {};
    for (const line of pending.value) {
        const available = line.track_stock ? Math.max(0, reservedFor(line) - (used[line.sku] || 0)) : line.remaining;
        items[line.key] = Math.min(line.remaining, available);
        used[line.sku] = (used[line.sku] || 0) + items[line.key];
    }
    ship.items = items;
}
const delivery = useForm({ parcel: '' });
const email = useForm({ parcel: '' });
const label = (line) => [line.name, ...line.options.map((option) => option.value)].join(' · ');
const slip = (parcel) => props.order.actions['packing-slip'] + '?parcel=' + encodeURIComponent(parcel.id);
watch(
    () => props.order,
    (order) => {
        ship.origin = order.stockLocations?.[0]?.code || 'main';
        reassign.request_id = order.reassignRequestId;
        reassign.reservation = 0;
        reassign.to = '';
        reassign.quantity = 1;
        ship.request_id = order.parcelRequestId;
        fillItems();
        receive.request_id = order.returnRequestId;
        receive.location = Object.keys(order.origins).length === 1 ? Object.keys(order.origins)[0] : '';
        receive.line_key = returnable.value[0]?.key || '';
    },
    { immediate: true },
);
function dispatchParcel() {
    ship.post(props.order.actions.ship, {
        preserveScroll: true,
        onSuccess: () => {
            ship.reset('tracking', 'tracking_url', 'notify');
            operation.value = '';
        },
    });
}
function receiveReturn() {
    receive.post(props.order.actions.receive, {
        preserveScroll: true,
        onSuccess: () => {
            receive.reset('quantity', 'restocked');
            operation.value = '';
        },
    });
}
function notify(parcel) {
    email.parcel = parcel.id;
    email.post(props.order.actions['shipment-email'], { preserveScroll: true });
}
</script>
<template>
    <Card inset>
        <div class="ss-section-title">
            <h2><Icon name="package-box-crate" /> {{ __('Fulfillment') }}</h2>
            <Badge :text="__(order.fulfillment)" :color="order.fulfillment === 'Shipped' ? 'green' : 'default'" />
        </div>
        <div class="ss-body ss-stack">
            <p v-if="order.returnsUrl">
                <a :href="order.returnsUrl" class="ss-order-link">{{ __('Return requests') }}<template v-if="order.openReturns"> · {{ order.openReturns }}</template></a>
            </p>
            <details v-if="order.stockLocations?.length"><summary>{{ __('Stock reserved by location') }}</summary><ul><li v-for="row in order.stockLocations" :key="row.sku+row.location">{{ row.quantity }} × {{ row.sku }} · {{ row.location }}</li></ul></details>

            <div v-if="order.actions.reassign && order.canPack && order.stockLocations?.length && Object.keys(order.origins).length > 1 && !order.activeLabel">
                <Button v-if="operation !== 'reassign'" size="sm" :text="__('Reassign reserved stock')" @click="operation = 'reassign'" />
                <form v-else class="ss-form" @submit.prevent="reassignStock">
                    <Field id="fulfillment-reservation" :label="__('Reservation')" :error="reassign.errors.sku">
                        <Select id="fulfillment-reservation" :model-value="String(reassign.reservation)" option-value="value" option-label="label"
                            :options="order.stockLocations.map((row, i) => ({ value: String(i), label: row.sku + ' · ' + row.location + ' (' + row.quantity + ')' }))"
                            @update:model-value="value => { reassign.reservation = Number(value); reassign.to = ''; reassign.quantity = 1 }" />
                    </Field>
                    <Field id="fulfillment-destination" :label="__('New dispatch location')" :error="reassign.errors.to">
                        <Select id="fulfillment-destination" v-model="reassign.to" :options="destinations.map(([value, label]) => ({ value, label }))" option-label="label" option-value="value" />
                    </Field>
                    <label>{{ __('Quantity') }}<Input type="number" v-model="reassign.quantity" min="1" :max="chosen?.quantity" required /></label>
                    <p v-for="error in reassign.errors" :key="error" role="alert">{{ error }}</p>
                    <div class="ss-actions"><Button :text="__('Cancel')" @click="operation = ''" /><Button type="submit" :disabled="reassign.processing" :text="__('Reassign reserved stock')" /></div>
                </form>
            </div>
            <div class="ss-parcel-heading">
                <strong>{{ order.method || order.fulfillment }}</strong>
                <a
                    v-if="order.actions['packing-slip'] && order.canPack"
                    :href="order.actions['packing-slip'] + '?remaining=1'"
                    target="_blank"
                    rel="noreferrer"
                    >{{ __('Print remaining items') }}</a
                >
            </div>
            <section class="ss-parcel" v-for="parcel in order.parcels" :key="parcel.id">
                <h3>{{ __('Parcel') }} {{ parcel.number }}</h3>
                <p v-if="parcel.origin || parcel.label_origin">{{ __('Dispatch from') }} · {{ order.origins[parcel.origin || parcel.label_origin] }}</p>
                <p class="ss-field-help">{{ __('Tracking ·') }} <a v-if="parcel.tracking_url" :href="parcel.tracking_url" rel="noreferrer" target="_blank">{{ parcel.tracking }}</a><span v-else>{{ parcel.tracking }}</span> {{ parcel.tracking_status }}</p>
                <p v-for="line in order.lines.filter((item) => parcel.items[item.key])" :key="line.key">
                    {{ parcel.items[line.key] }} × {{ label(line) }}
                </p>
                <div class="ss-actions">
                    <a
                        v-if="order.actions['packing-slip']"
                        :href="slip(parcel)"
                        target="_blank"
                        rel="noreferrer"
                        >{{ __('Reprint parcel') }}</a
                    >
                    <Badge v-if="parcel.delivered_at || parcel.tracking_status?.trim().toLowerCase() === 'delivered'" :text="__('Delivered')" color="green" />
                    <Button v-else-if="order.actions.delivered" size="sm" :disabled="delivery.processing" :text="__('Mark delivered')"
                        @click="delivery.parcel = parcel.id; delivery.post(order.actions.delivered, { preserveScroll: true })" />
                    <span v-if="parcel.emailed_at">{{ __('Shipment email sent') }}</span
                    ><span v-else-if="parcel.queued_at">{{ __('Shipment email queued') }}</span>
                    <Button
                        v-else-if="order.actions['shipment-email']"
                        size="sm"
                        :text="__('Email shipment details')"
                        :disabled="email.processing"
                        @click="notify(parcel)"
                    />
                </div>
            </section>
            <section v-if="order.activeLabel && order.actions.labels">
                <h3>{{ __('Carrier label') }}</h3>
                <p>{{ order.activeLabel.status }} · {{ order.activeLabel.tracking || order.activeLabel.origin }}</p>
                <div v-if="order.activeLabel.status === 'booked'" class="ss-actions">
                    <a :href="order.activeLabel.label_url" target="_blank" rel="noreferrer">{{ __('Print label') }}</a>
                    <Button :text="__('Dispatch labeled parcel')" :disabled="ship.processing" @click="dispatchLabel" />
                    <Button :text="__('Void label')" :disabled="carrierForm.processing" @click="carrierAction('void')" />
                </div>
                <Button v-else :text="__('Retry carrier request')" :disabled="carrierForm.processing" @click="carrierAction(order.activeLabel.status === 'voiding' ? 'void' : 'book')" />
            </section>
            <p v-for="error in carrierForm.errors" :key="error" role="alert">{{ error }}</p>
            <div class="ss-link-actions" v-if="!operation">
                <Button
                    v-if="
                        !order.activeLabel && order.actions.ship &&
                        pending.length &&
                        ['paid', 'awaiting_collection'].includes(order.status)
                    "
                    :text="__('Ship a parcel')"
                    @click="startShipment"
                />
                <Button
                    v-if="order.actions.receive && returnable.length"
                    :text="__('Receive a return')"
                    @click="operation = 'return'"
                />
            </div>
            <form
                v-if="
                    operation === 'ship' &&
                    !order.activeLabel && order.actions.ship &&
                    pending.length &&
                    ['paid', 'awaiting_collection'].includes(order.status)
                "
                class="ss-form"
                @submit.prevent="dispatchParcel"
            >
                <h3 ref="shipmentHeading" tabindex="-1">{{ __('Ship a parcel') }}</h3>
                <label v-for="line in pending" :key="line.key"
                    >{{ label(line) }} · {{ line.remaining }} {{ __('awaiting shipment') }}
                    <Input
                        type="number"
                        min="0"
                        :max="line.track_stock ? Math.min(line.remaining, reservedFor(line)) : line.remaining"
                        required
                        v-model="ship.items[line.key]"
                /></label>
                <Field id="fulfillment-origin" :label="__('Dispatch from')" :error="ship.errors.origin">
                    <Select id="fulfillment-origin" :model-value="ship.origin" :options="origins" option-label="label" option-value="value" @update:model-value="value => { ship.origin = value; fillItems() }" />
                </Field>
                <div v-if="order.carrier && order.actions.labels">
                    <Button type="button" :text="__('Book carrier label')" :disabled="carrierForm.processing" @click="carrierAction('book')" />
                </div>
                <label>{{ __('Tracking number') }}<Input v-model="ship.tracking" required maxlength="250" /></label>
                <label>{{ __('Carrier tracking link (optional)') }}<Input type="url" v-model="ship.tracking_url" maxlength="4096" placeholder="https://" /><small class="ss-field-error" v-if="ship.errors.tracking_url">{{ ship.errors.tracking_url }}</small></label>
                <label><input type="checkbox" v-model="ship.notify" /> {{ __('Email tracking to customer') }}</label>
                <p v-for="error in ship.errors" :key="error" role="alert">{{ error }}</p>
                <div class="ss-actions">
                    <Button :text="__('Cancel')" type="button" @click="operation = ''" /><Button
                        type="submit"
                        variant="primary"
                        :text="__('Ship parcel')"
                        :disabled="ship.processing"
                    />
                </div>
            </form>
            <form
                v-if="operation === 'return' && order.actions.receive && returnable.length"
                class="ss-form"
                @submit.prevent="receiveReturn"
            >
                <h3>{{ __('Receive a return') }}</h3>
                <Field id="fulfillment-return-item" :label="__('Return item')" :error="receive.errors.line_key">
                    <Select id="fulfillment-return-item" v-model="receive.line_key"
                        :options="returnable.map(line => ({ value: line.key, label: label(line) + ' · ' + line.returnable + ' ' + __('eligible') }))" option-label="label" option-value="value" @update:model-value="receive.restocked = 0" />
                </Field>
                <div class="ss-field-grid">
                    <label
                        >{{ __('Received') }}<Input
                            v-model="receive.quantity"
                            type="number"
                            min="1"
                            :max="returnable.find((line) => line.key === receive.line_key)?.returnable"
                            required
                    /></label>
                    <label v-if="returnable.find(line => line.key === receive.line_key)?.track_stock"
                        >{{ __('Fit to restock') }}<Input
                            v-model="receive.restocked"
                            type="number"
                            min="0"
                            :max="receive.quantity"
                            required
                    /></label>
                </div>
                <Field v-if="Number(receive.restocked) > 0" id="fulfillment-restock" :label="__('Restock at')" :error="receive.errors.location">
                    <Select id="fulfillment-restock" v-model="receive.location" :options="origins" option-label="label" option-value="value" :placeholder="__('Choose a receiving location.')" />
                </Field>
                <p v-for="error in receive.errors"  :key="error" role="alert">{{ error }}</p>
                <div class="ss-actions">
                    <Button :text="__('Cancel')" type="button" @click="operation = ''" /><Button
                        type="submit"
                        :text="__('Record return')"
                        :disabled="receive.processing"
                    />
                </div>
            </form>
        </div>
    </Card>
</template>
