<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@statamic/cms/inertia';
import { Button, Card, Input } from '@statamic/cms/ui';
import OperationsPage from './OperationsPage.vue';
import DraftAddress from './DraftAddress.vue';
const props = defineProps({
    contact: Object,
    orders: Object,
    book: Object,
    countries: Object,
    saveUrl: String, inviteUrl: String,
    listUrl: String,
});
const invitation = useForm({});
const editing = ref(false);
const form = useForm({
    id: null,
    action: 'save',
    label: '',
    address: {},
    billing: false,
    shipping: false,
    revision: 0,
});
const countriesList = Object.entries(props.countries).map(([value, label]) => ({ value, label }));
function edit(row) {
    form.clearErrors();
    Object.assign(form, {
        id: row?.id ?? null,
        label: row?.label || '',
        address: { ...row?.address },
        billing: !!row && props.book.billing === row.id,
        shipping: !!row && props.book.shipping === row.id,
        revision: props.book.revision,
        action: 'save',
    });
    editing.value = true;
}
function save(action) {
    form.action = action;
    form.post(props.saveUrl, { onSuccess: () => (editing.value = false), preserveScroll: true });
}
</script>
<template>
    <OperationsPage :title="contact.name || contact.email" icon="user-avatar" :records="orders"
        :parent-url="listUrl" :parent-title="__('Customers')">
        <Card
            ><p>{{ contact.email }} · {{ contact.id ? __('Registered account') : __('Guest') }}</p>
            <Button v-if="inviteUrl" :text="__('Invite to claim orders')" :disabled="invitation.processing" @click="invitation.post(inviteUrl, { preserveScroll: true })" />
            </Card
        >
        <Card
            ><div class="ss-actions">
                <h2>{{ __('Address book') }}</h2>
                <Button :text="__('Add address')" @click="edit(null)" />
            </div>
            <p v-if="!book.entries.length">{{ __('No saved addresses.') }}</p>
            <ul class="ss-record-list">
                <li v-for="entry in book.entries">
                    <strong>{{ entry.label }}</strong
                    ><small>{{
                        [entry.address.street, entry.address.city, entry.address.country]
                            .filter(Boolean)
                            .join(', ')
                    }}</small
                    ><small>{{
                        [
                            book.billing === entry.id ? __('Billing default') : '',
                            book.shipping === entry.id ? __('Shipping default') : '',
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}</small
                    ><Button :text="__('Edit address')" size="sm" @click="edit(entry)" />
                </li>
            </ul>
            <form v-if="editing" class="ss-form" @submit.prevent="save('save')">
                <label>{{ __('Address label') }}<Input v-model="form.label" required maxlength="80" /></label
                ><DraftAddress
                    v-model="form.address"
                    prefix="address"
                    :countries="countriesList"
                    :required-fields="['street', 'city']"
                    :errors="form.errors"
                    recipient
                />
                <label><input v-model="form.billing" type="checkbox" /> {{ __('Default billing address') }}</label
                ><label><input v-model="form.shipping" type="checkbox" /> {{ __('Default shipping address') }}</label>
                <div class="ss-actions">
                    <Button
                        type="submit"
                        variant="primary"
                        :text="__('Save address')"
                        :disabled="form.processing"
                    /><Button :text="__('Cancel')" @click="editing = false" /><Button
                        v-if="form.id"
                        :text="__('Remove address')"
                        @click="save('delete')"
                    />
                </div></form
        ></Card>
        <Card
            ><h2>{{ __('Orders') }}</h2>
            <p v-if="!orders.data.length">{{ __('No orders for this customer on this site.') }}</p>
            <ul class="ss-record-list">
                <li v-for="order in orders.data">
                    <Link v-if="order.url" :href="order.url">{{ __('Order') }} {{ order.number }}</Link
                    ><strong v-else>{{ __('Order') }} {{ order.number }}</strong
                    ><small>{{ order.date }} · {{ order.status }} · {{ order.total }}</small>
                </li>
            </ul></Card
        ></OperationsPage
    >
</template>
