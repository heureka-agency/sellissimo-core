<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@statamic/cms/inertia';
import {
    Badge,
    Modal,
    Button,
    Card,
    Icon,
    Header,
    Input,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';
import AmendmentCard from './AmendmentCard.vue';
import DraftAddress from './DraftAddress.vue';
import ProviderDetails from './ProviderDetails.vue';
import PaymentBadge from './PaymentBadge.vue';
import OrderEdits from './OrderEdits.vue';
import FulfillmentCard from './FulfillmentCard.vue';
import OrderActivity from './OrderActivity.vue';
import CancellationDialog from './CancellationDialog.vue';
import RefundDialog from './RefundDialog.vue';

const props = defineProps({
    order: Object, csrfToken: String,
    listUrl: String,
    listLabel: String,
    activity: Object,
    noteUrl: String,
    noteRequestId: String,
});

const reconcile = useForm({});
const editingAddress = ref(false);
const destination = useForm({ shipping_address: {}, previous: '' });
const countries = computed(() => Object.entries(props.order.countries).map(([value, label]) => ({ value, label })));
function editAddress() {
    destination.shipping_address = { ...(props.order.shippingAddress || props.order.address) };
    destination.previous = props.order.deliveryVersion;
    destination.clearErrors();
    editingAddress.value = true;
}
function saveAddress() {
    destination.transform(() => ({ previous: destination.previous, shipping_address: { ...destination.shipping_address } }))
        .post(props.order.actions.address, { preserveScroll: true, onSuccess: () => editingAddress.value = false });
}
const fulfillment = ref(null);
const collection = useForm({ action: 'collect' });
const cancelOpen = ref(false);
function settle(action) {
    collection.action = action;
    collection.post(props.order.actions['settle-delivery'], { preserveScroll: true, onSuccess: () => (cancelOpen.value = false) });
}
const receipt = useForm({});
const access = useForm({ sku: '', blocked: false });
const digital = computed(() => props.order.lines.filter((line) => line.downloadable));
function addressLines(a, country, recipient = false) {
    return [recipient && a.name, a.company, a.street, a.street2, [a.city, a.region, a.postal_code].filter(Boolean).join(', '), country || a.country, a.phone].filter(Boolean);
}
const refundNames = { '@provider': 'Provider refund', '@all': 'Remaining balance', '@shipping': 'Shipping' };
function toggleDownload(line) {
    access.sku = line.key;
    access.blocked = !line.blocked;
    access.post(props.order.actions['download-access'], { preserveScroll: true });
}
</script>
<template>
    <Head :title="__('Order') + ' ' + order.number" />
    <div class="ss-page ss-order">
        <Link class="ss-back" :href="listUrl">
            <Icon name="arrow-left" />
            {{ __(listLabel) }}
        </Link>
        <Header :title="__('Order') + ' ' + order.number" icon="shopping-cart">
            <template #actions>
                <Button v-if="order.actions['settle-delivery'] && order.status === 'awaiting_collection'" variant="primary"
                    :text="__('Record payment collected')" :disabled="collection.processing" @click="settle('collect')" />
                <Button v-else-if="order.actions.ship && !order.activeLabel && order.status === 'paid' && order.lines.some(line => line.remaining > 0)"
                    variant="primary" :text="__('Ship a parcel')" @click="fulfillment?.startShipment()" />
            </template>
        </Header>
        <div class="ss-order-meta">
            <span>{{ order.date }} · {{ order.time }}</span>
            <Badge v-if="['cancelled','expired'].includes(order.status)" :text="__(order.status === 'cancelled' ? 'Cancelled' : 'Expired')" />
            <PaymentBadge :status="order.paymentState" />
            <Badge :text="__(order.fulfillment)" />
        </div>
        <div class="ss-record-layout">
            <div class="ss-stack">
                <Card v-if="order.disputes.length" inset>
                    <h2 class="ss-section-title">{{ __('Payment disputes') }}</h2>
                    <p v-if="order.disputeAlert" class="ss-inline"><Badge :text="__('Review before dispatch')" color="red" /> {{ __('Check the dispute with your payment provider before shipping.') }}</p>
                    <ul><li v-for="dispute in order.disputes" :key="dispute.id" class="ss-summary-line">
                        <div><strong>{{ __(dispute.status) }} · {{ dispute.amount }}</strong>
                            <small>{{ dispute.reason }} · {{ dispute.id }}</small>
                            <small v-for="movement in dispute.movements" :key="movement.reference">{{ movement.date || __('Provider date pending') }} · {{ movement.amount }}<span v-if="movement.fee"> · {{ __('Dispute fees') }} {{ movement.fee }}</span></small>
                            <small v-if="dispute.respondBy && ['Needs Response', 'Warning Needs Response'].includes(dispute.status)">{{ __('Respond by') }} {{ dispute.respondBy }}</small>
                        </div>
                    </li></ul>
                </Card>
                <Card inset>
                    <h2 class="ss-section-title">
                        <Icon name="shopping-cart" />
                        {{ __('Order items') }}
                    </h2>
                    <OrderEdits v-if="order.fieldsUrl" :order mode="fields" />
                    <dl v-if="order.fields.length" class="ss-facts"><div v-for="field in order.fields" :key="field.label"><dt>{{ field.label }}<small v-if="field.internal">{{ __('Staff only') }}</small></dt>
                        <dd><a v-if="field.url" :href="field.url">{{ field.value }}</a><template v-else>{{ field.value }}</template></dd></div></dl>
                    <div class="ss-table-wrap">
                        <Table class="ss-table">
                            <TableColumns>
                                <TableColumn>{{ __('Item') }}</TableColumn>
                                <TableColumn class="ss-number">{{ __('Qty') }}</TableColumn>
                                <TableColumn class="ss-number">{{ __('Amount') }}</TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="line in order.lines" :key="line.key">
                                    <TableCell>
                                        <small v-if="order.returnPolicies[line.key]">{{ order.returnPolicies[line.key].label }}</small>
                                        <strong>{{ line.name }}</strong>
                                        <small v-for="field in line.fields" :key="field.label">{{ field.label }} · <a v-if="field.url" :href="field.url">{{ field.value }}</a><template v-else>{{ field.value }}</template></small>
                                        <small v-for="saving in line.savings" :key="saving">{{ saving }}</small>
                                        <small>{{ line.sku }}</small
                                        ><small v-for="option in line.options" :key="option.handle"
                                            >{{ option.label }} · {{ option.value }}</small
                                        >
                                    </TableCell>
                                    <TableCell class="ss-number">{{ line.qty }}</TableCell>
                                    <TableCell class="ss-number">{{ line.amount }}<small v-if="line.unusedVoucher">{{ __('Unused voucher balance') }} · {{ line.unusedVoucher }}</small></TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </div>
                </Card>
                <Card v-if="order.shippingInstructions"><h2 class="ss-section-title">{{ __('Delivery instructions') }}</h2><p class="ss-body">{{ order.shippingInstructions }}</p></Card>
                <ProviderDetails :rows="order.providerDetails.shipping" />
                <Card v-for="(panel, handle) in order.extensionPanels" :key="handle">
                    <h2 class="ss-section-title">{{ panel.title }}</h2>
                    <a class="ss-link" :href="panel.url">{{ panel.label }}</a>
                </Card>
                <FulfillmentCard ref="fulfillment" v-if="order.lines.some(line => !line.digital)" :order />
                <Card v-if="order.refunds.length" inset>
                    <h2 class="ss-section-title">
                        <Icon name="credit-card" />
                        {{ __('Refund history') }}
                    </h2>
                    <ul class="ss-body">
                        <li v-for="(refund, i) in order.refunds" :key="i" class="ss-summary-line">
                            <span>
                                {{ refundNames[refund.sku] ? __(refundNames[refund.sku]) : (refund.label || refund.sku) }}
                                <small>{{ refund.toVoucher ? __('Refunded to gift voucher') : refund.manual ? __('Manual refund · ') + refund.reference : [refund.status, refund.reference].filter(Boolean).join(' · ') }}</small>
                            </span>
                            <strong>{{ refund.amount }}</strong>
                        </li>
                    </ul>
                </Card>
                <AmendmentCard :order :countries :csrf="csrfToken" />
                <OrderActivity :order :activity :note-url :note-request-id />
            </div>
            <aside class="ss-stack">
                <Card inset>
                    <h2 class="ss-section-title">{{ __('Order summary') }}</h2>
                    <dl class="ss-totals">
                        <div>
                            <dt>{{ __('Subtotal') }}</dt>
                            <dd>{{ order.subtotal }}</dd>
                        </div>
                        <div v-for="offer in order.promotions" :key="offer.id">
                            <dt>{{ offer.label }}</dt>
                            <dd>{{ offer.free_shipping ? __('Free shipping') : '−' + offer.formatted }}</dd>
                        </div>
                        <div v-if="order.discount && !order.promotions.length">
                            <dt>{{ __('Discount') }}</dt><dd>{{ order.discount }}</dd>
                        </div>
                        <div v-for="(charge, handle) in order.charges" :key="handle">
                            <dt>{{ charge.label }}</dt>
                            <dd>{{ charge.amount }}</dd>
                        </div>
                        <div v-if="order.tax">
                            <dt>{{ __('Tax') }}</dt>
                            <dd>{{ order.tax }}<small v-if="order.deliveryTax">{{ __('Includes') }} {{ order.deliveryTax }} {{ __('delivery tax') }}</small></dd>
                        </div>
                        <div v-if="order.lines.some(line => !line.digital)">
                            <dt>{{ __('Shipping') }}</dt>
                            <dd>{{ order.shipping }}</dd>
                        </div>
                        <div class="ss-total">
                            <dt>
                                {{ __('Total') }}
                                <small>{{ order.currency }}</small>
                            </dt>
                            <dd>{{ order.total }}</dd>
                        </div>
                    </dl>
                </Card>
                <Card inset>
                    <h2 class="ss-section-title">
                        <Icon name="user-avatar" />
                        {{ __('Customer') }}
                    </h2>
                    <div class="ss-body">
                        <strong v-if="order.name">{{ order.name }}</strong>
                        <p>
                            <a class="ss-mail" :href="'mailto:' + order.email">{{ order.email }}</a>
                        </p>
                        <OrderEdits v-if="order.contactUrl" :order mode="contact" />
                        <div v-if="Object.keys(order.address).length" class="ss-address">
                            <h3>{{ order.shippingAddress || order.lines.every(line => line.digital) ? __('Billing address') : __('Billing and shipping address') }}</h3>
                            <address><div v-for="(line, i) in addressLines(order.address, order.countryLabel)" :key="i">{{ line }}</div></address>
                        </div>
                        <div v-if="order.shippingAddress" class="ss-address"><h3>{{ __('Shipping address') }}</h3>
                            <address><div v-for="(line, i) in addressLines(order.shippingAddress, order.shippingCountry, true)" :key="i">{{ line }}</div></address>
                        </div>
                        <Button v-if="order.canCorrectAddress && order.actions.address" size="sm" :text="__('Correct delivery address')" @click="editAddress" />
                        <Modal v-model:open="editingAddress" :title="__('Correct delivery address')">
                            <form @submit.prevent="saveAddress">
                                <DraftAddress v-model="destination.shipping_address" prefix="shipping_address" recipient :countries :errors="destination.errors" delivery />
                                <p v-for="error in destination.errors" :key="error" role="alert">{{ error }}</p>
                                <Button type="submit" :text="__('Save delivery address')" :disabled="destination.processing" />
                            </form>
                        </Modal>
                    </div>
                </Card>
                <Card inset>
                    <h2 class="ss-section-title">
                        <Icon name="credit-card" />
                        {{ __('Payment') }}
                    </h2>
                    <div class="ss-body">
                        <dl class="ss-facts">
                            <div>
                                <dt>{{ __('Method') }}</dt>
                                <dd>{{ order.gateway }}</dd>
                            </div>
                            <div v-if="order.voucherTender">
                                <dt>{{ __('Original gift voucher payment') }}</dt><dd>{{ order.voucherTender }}</dd>
                                <dt>{{ __('Original other payment') }}</dt><dd>{{ order.originalCash }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Status') }}</dt>
                                <dd><PaymentBadge :status="order.paymentState" /></dd>
                            </div>
                            <div v-if="order.refunded">
                                <dt>{{ __('Refunded') }}</dt>
                                <dd>{{ order.refunded }}</dd>
                            </div>
                        </dl>
                        <div v-if="order.actions['settle-delivery'] && order.status === 'awaiting_collection' && !order.tracking" class="ss-action-row">
                            <Button
                                :text="__('Cancel unshipped order')"
                                :disabled="collection.processing"
                                @click="cancelOpen = true"
                            />
                        </div>
                        <p v-for="error in collection.errors" :key="error" role="alert">{{ error }}</p>
                        <div v-if="order.status === 'paid'" class="ss-action-row">
                            <Button
                                size="sm"
                                v-if="order.actions.resend"
                                :text="__('Resend receipt')"
                                :disabled="receipt.processing"
                                @click="receipt.post(order.actions.resend, { preserveScroll: true })"
                            />
                            <RefundDialog v-if="(order.canRefund || order.canManualRefund) && order.actions.refund" :order />
                        </div>
                        <Button v-for="(url, gateway) in order.reconcileLinks" :key="gateway" size="sm"
                            :text="__('Refresh :gateway payments', { gateway: gateway.charAt(0).toUpperCase() + gateway.slice(1) })" :disabled="reconcile.processing"
                            @click="reconcile.post(url, { preserveScroll: true })" />
                        <p v-for="error in reconcile.errors" :key="error" role="alert">{{ error }}</p>
                        <p v-if="order.cancellationReason">{{ order.cancellationReason }}</p>
                        <CancellationDialog v-if="order.actions.cancel && (order.canCancel || order.canRetryCancellation)" :order />
                        <p v-for="error in receipt.errors" :key="error" role="alert">
                            {{ error }}
                        </p>
                    </div>
                </Card>
                <ProviderDetails :rows="order.providerDetails.payment" />
                <Card v-if="digital.length" inset>
                    <h2 class="ss-section-title">
                        <Icon name="download" />
                        {{ __('Digital downloads') }}
                    </h2>
                    <div v-for="line in digital" :key="line.key" class="ss-body ss-download">
                        <strong>{{ line.name }}</strong
                        ><small v-for="option in line.options" :key="option.handle"
                            >{{ option.label }} · {{ option.value }}</small
                        >
                        <small v-if="line.downloadUsage">{{ line.downloadUsage }}</small>
                        <div class="ss-summary-line">
                            <Badge
                                :color="line.accessible ? 'green' : 'default'"
                                :text="
                                    line.blocked
                                        ? __('Blocked')
                                        : line.accessible
                                          ? __('Access granted')
                                          : __('No access')
                                "
                            />
                            <Button
                                v-if="order.actions['download-access'] && (line.blocked || line.accessible)"
                                size="sm"
                                variant="ghost"
                                :text="line.blocked ? __('Remove block') : __('Block access')"
                                :disabled="access.processing"
                                @click="toggleDownload(line)"
                            />
                        </div>
                    </div>
                    <p v-for="error in access.errors" :key="error" role="alert">
                        {{ error }}
                    </p>
                </Card>
            </aside>
        </div>
    </div>
    <Modal v-model:open="cancelOpen" :title="__('Cancel this order?')"><p class="ss-summary-line ss-record"><span>{{ __('Total') }}</span><strong>{{ order.total }}</strong></p><p>{{ __('Reserved stock will be released and payment collection will close.') }}</p>
        <div class="ss-dialog-actions"><Button :text="__('Keep order')" @click="cancelOpen = false" /><Button :text="__('Cancel order')" variant="danger" :disabled="collection.processing" @click="settle('cancel')" /></div></Modal>
</template>
