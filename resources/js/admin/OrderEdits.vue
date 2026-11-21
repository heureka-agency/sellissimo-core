<script setup>
import { ref } from 'vue';
import { useForm } from '@statamic/cms/inertia';
import { Button, Modal } from '@statamic/cms/ui';
import CommerceFields from './CommerceFields.vue';

const props = defineProps({ order: Object, mode: String });
const open = ref(false);
const contact = useForm({ email: '', email_confirmation: '', reason: '', verified: false, revision: 0 });
const fields = useForm({ fields: {}, revision: 0 });
function start() {
    contact.reset();
    contact.clearErrors();
    contact.revision = props.order.contactRevision;
    fields.clearErrors();
    fields.fields = { ...props.order.fieldInputs };
    fields.revision = props.order.fieldsRevision;
    open.value = true;
}
function saveFields() {
    const patch = Object.fromEntries(Object.entries(fields.fields).filter(([key, value]) => value !== props.order.fieldInputs[key]));
    if (!Object.keys(patch).length) { open.value = false; return; }
    fields.transform(() => ({ fields: patch, revision: fields.revision })).post(props.order.fieldsUrl, {
        preserveScroll: true, onSuccess: () => open.value = false,
    });
}
function saveContact() {
    contact.post(props.order.contactUrl, { preserveScroll: true, onSuccess: () => open.value = false });
}
</script>
<template>
    <Button size="sm" :text="mode === 'fields' ? __('Edit staff fields') : __('Correct guest email')" @click="start" />
    <Modal v-model:open="open" :title="mode === 'fields' ? __('Edit staff fields') : __('Correct guest email')">
        <form class="ss-stack ss-form" v-if="mode === 'contact'" @submit.prevent="saveContact">
            <p>{{ __('Current receipt email') }} · {{ order.email }}</p>
            <label>{{ __('Corrected email') }}<input v-model.trim="contact.email" type="email" required maxlength="254" /></label>
            <label>{{ __('Confirm corrected email') }}<input v-model.trim="contact.email_confirmation" type="email" required /></label>
            <label>{{ __('How was the buyer verified?') }}<input v-model.trim="contact.reason" required maxlength="250" /></label>
            <label><input v-model="contact.verified" type="checkbox" required /> {{ __('I verified the buyer and the new address.') }}</label>
            <p>{{ __('Earlier receipt links expire. A fresh link goes to the corrected address.') }}</p>
            <p v-for="error in contact.errors" :key="error" role="alert">{{ error }}</p>
            <Button type="submit" variant="primary" :disabled="contact.processing" :text="__('Save and send receipt link')" />
        </form>
        <form v-else class="ss-stack ss-form" @submit.prevent="saveFields">
            <CommerceFields v-model="fields.fields" :fields="order.fieldControls" />
            <p v-for="error in fields.errors" :key="error" role="alert">{{ error }}</p>
            <Button type="submit" variant="primary" :disabled="fields.processing" :text="__('Save fields')" />
        </form>
    </Modal>
</template>
