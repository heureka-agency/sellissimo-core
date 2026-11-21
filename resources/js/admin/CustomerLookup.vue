<script setup>
import { ref, watch } from 'vue';
import { Button, Input, Modal } from '@statamic/cms/ui';

const props = defineProps({ url: String, site: String, accountsOnly: Boolean });
const emit = defineEmits(['select']);
const open = ref(false);
const search = ref('');
const matches = ref([]);
const loading = ref(false);
const error = ref('');
const retry = ref(0);
watch(open, () => {
    search.value = '';
});
watch([open, search, () => props.site, retry], ([visible, term], _, cleanup) => {
    matches.value = [];
    error.value = '';
    loading.value = visible && term.trim().length !== 1;
    if (!loading.value) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
        try {
            const url = new URL(props.url);
            url.searchParams.set('q', term.trim());
            if (props.site) url.searchParams.set('site', props.site);
            const response = await fetch(url, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error();
            const rows = await response.json();
            if (!controller.signal.aborted) matches.value = rows;
        } catch {
            if (!controller.signal.aborted)
                error.value =
                    __('Customer search could not be loaded. Try again.');
        } finally {
            if (!controller.signal.aborted) loading.value = false;
        }
    }, 200);
    cleanup(() => {
        clearTimeout(timer);
        controller.abort();
    });
});
function select(customer) {
    emit('select', customer);
    open.value = false;
}
</script>
<template>
    <Button type="button" size="sm" variant="ghost" :text="__('Select customer')" @click="open = true" />
    <Modal v-model:open="open" :title="__('Select a customer')">
        <div class="ss-form">
            <label
                >{{ __('Search customers') }}<Input
                    v-model="search"
                    autocomplete="off"
                    :placeholder="__('Customer name or email')"
                    maxlength="150"
            /></label>
            <p class="ss-field-help" role="status">
                {{
                    error ||
                    (loading
                        ? __('Searching…')
                        : search.trim().length === 1
                          ? __('Enter another character to search.')
                          : !matches.length
                            ? __('No customers found on this site.')
                            : accountsOnly ? __('Registered customers.') : __('Registered customers and contacts from previous guest orders.'))
                }}
            </p>
            <Button v-if="error" type="button" :text="__('Try again')" @click="retry++" />
            <ul v-if="matches.length" class="ss-matches">
                <li v-for="customer in matches" :key="customer.email">
                    <button type="button" @click="select(customer)">
                        <strong>{{ customer.address.name || customer.email }}</strong>
                        <small>{{
                            [
                                customer.address.name ? customer.email : '',
                                customer.customer_id ? __('Registered customer') : __('Previous guest'),
                                customer.address.city,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}</small>
                    </button>
                </li>
            </ul>
        </div>
        <template #footer><Button :text="__('Close')" @click="open = false" /></template>
    </Modal>
</template>
