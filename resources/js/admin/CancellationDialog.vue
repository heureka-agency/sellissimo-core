<script setup>
import { ref } from 'vue';
import { Button, Input, Modal, Textarea } from '@statamic/cms/ui';
import { useForm } from '@statamic/cms/inertia';

const props = defineProps({ order: Object });
const open = ref(false);
const offline = ref(false);
const form = useForm({ request_id: '', reason: '', restock: true, notify: true, reference: '' });
function show() {
    form.reset();
    form.clearErrors();
    form.request_id = props.order.requestId;
    offline.value = false;
    open.value = true;
}
function submit() {
    if (!offline.value) form.reference = '';
    const action = props.order.canRetryCancellation ? 'cancel.retry' : 'cancel';
    form.post(props.order.actions[action], { preserveScroll: true, onSuccess: () => open.value = false });
}
</script>
<template>
    <Button size="sm" :text="order.canRetryCancellation ? __('Retry cancellation refund') : __('Cancel order')" @click="show" />
    <Modal v-model:open="open" :title="order.canRetryCancellation ? __('Retry cancellation refund') : __('Cancel order')" @closed="open = false">
        <form class="ss-form" @submit.prevent="submit">
            <p>{{ __('Remaining refundable balance:') }} {{ order.balance }}</p>
            <template v-if="!order.canRetryCancellation">
                <label>{{ __('Reason for cancellation') }}<Textarea v-model="form.reason" required maxlength="500" /></label>
                <label v-if="order.lines.some(line => !line.digital)"><input v-model="form.restock" type="checkbox" /> {{ __('Return stock to its original locations') }}</label>
                <label><input v-model="form.notify" type="checkbox" /> {{ __('Email the buyer') }}</label>
            </template>
            <label><input v-model="offline" type="checkbox" /> {{ __('I have already refunded the remaining balance offline') }}</label>
            <label v-if="offline">{{ __('Refund reference') }}<Input v-model="form.reference" required maxlength="150" /></label>
            <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
            <div class="ss-actions"><Button type="button" :text="__('Back')" @click="open = false" />
                <Button type="submit" variant="danger" :disabled="form.processing" :text="order.canRetryCancellation ? __('Retry refund') : __('Confirm cancellation')" /></div>
        </form>
    </Modal>
</template>
