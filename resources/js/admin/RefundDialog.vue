<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@statamic/cms/inertia';
import { Modal, Button, Input, Field, Select } from '@statamic/cms/ui';

const props = defineProps({ order: Object });
const open = ref(false);
const form = useForm({ request_id: '', sku: '@all', display_amount: '', manual: false, reason: '', reference: '' });
const target = computed(() => props.order.targets.find((item) => item.sku === form.sku));
const maximum = computed(() =>
    target.value ? (target.value.maximum / 10 ** props.order.digits).toFixed(props.order.digits) : '',
);
const formatted = computed(() =>
    form.sku === '@all'
        ? props.order.balance
        : new Intl.NumberFormat(document.documentElement.lang || 'en', {
              style: 'currency',
              currency: props.order.currency,
              minimumFractionDigits: props.order.digits,
              maximumFractionDigits: props.order.digits,
          }).format(Number(form.display_amount) || 0),
);
watch([() => form.sku, maximum], () => (form.display_amount = maximum.value));

function show(manual) {
    form.reset();
    form.manual = manual;
    form.clearErrors();
    form.sku = '@all';
    form.display_amount = '';
    form.request_id = props.order.requestId;
    open.value = true;
}
function submit() {
    form.transform((data) => ({ ...data, display_amount: String(data.display_amount) })).post(
        props.order.actions.refund,
        { preserveScroll: true, onSuccess: () => (open.value = false) },
    );
}
</script>
<template>
    <Button v-if="order.canRefund" :text="__('Issue refund')" size="sm" @click="show(false)" />
    <Button v-if="order.canManualRefund" :text="__('Record manual refund')" size="sm" @click="show(true)" />
    <Modal v-model:open="open" :title="form.manual ? __('Record manual refund') : __('Issue refund')">
        <form class="ss-form" @submit.prevent="submit">
            <div class="ss-summary-line ss-record">
                <span>{{ __('Refundable balance') }}</span>
                <strong>{{ order.balance }}</strong>
            </div>
            <Field id="refund-target" :label="__('Refund for')" :error="form.errors.sku">
                <Select id="refund-target" v-model="form.sku" :options="[{ sku: '@all', label: __('Remaining order balance') }, ...order.targets]" option-label="label" option-value="sku" />
            </Field>
            <label v-if="form.sku !== '@all'">
                {{ __('Amount (') }}{{ order.currency }})
                <Input
                    v-model="form.display_amount"
                    type="number"
                    :step="10 ** -order.digits"
                    :min="10 ** -order.digits"
                    :max="maximum"
                    required
                />
            </label>
            <template v-if="form.manual">
                <label>{{ __('Reason') }}<Input v-model="form.reason" maxlength="250" required /></label>
                <label>{{ __('Payment reference') }}<Input v-model="form.reference" maxlength="150" required /></label>
                <p>{{ __('Record money already returned to the customer.') }}</p>
            </template>
            <p v-else>{{ __('This sends a refund to the customer’s original payment method.') }}</p>
            <p v-for="error in form.errors" :key="error" role="alert">
                {{ error }}
            </p>
            <div class="ss-actions">
                <Button type="button" :text="__('Cancel')" @click="open = false" />
                <Button type="submit" variant="danger" :text="(form.manual ? __('Record ') : __('Refund ')) + formatted" :disabled="form.processing" />
            </div>
        </form>
    </Modal>
</template>
