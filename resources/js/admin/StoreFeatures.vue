<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@statamic/cms/inertia';
import { Button, CardPanel, Checkbox, CheckboxGroup, Field, Input, Select } from '@statamic/cms/ui';
import SettingsHeader from './SettingsHeader.vue';
import SettingsErrors from './SettingsErrors.vue';
import { useSettingsChanges } from './useSettingsChanges';

const props = defineProps({ site: String, sites: Object, multisite: Boolean, section: String, settings: Object, url: String, settingsUrl: String, settingsTitle: String });
const flags = { wishlists: 'Wishlists', back_in_stock: 'Back-in-stock alerts', vouchers: 'Gift vouchers' };
const recommendationTypes = { cross_sell: 'Complementary products', upsell: 'Product upgrades' };
const modes = [
    { value: 'off', label: __('Off') },
    { value: 'manual', label: __('Hand-picked only') },
    { value: 'auto', label: __('Hand-picked and automatic') },
];
const topics = { 'order.placed': 'New orders', 'stock.low': 'Low stock', 'stock.exhausted': 'Out of stock' };
const emails = computed(() => props.section === 'emails');
const form = useForm({ flags: [], mail: {} });
watch(() => props.settings, settings => {
    form.flags = [...settings.features];
    form.mail = Object.fromEntries(Object.keys(topics).map(topic => [topic, (settings.recipients[topic] || []).join(', ')]));
    form.defaults();
}, { immediate: true });
const changes = useSettingsChanges(() => form.isDirty);
function mode(type) {
    if (!form.flags.includes(type)) return 'off';
    return form.flags.includes('auto_' + type) ? 'auto' : 'manual';
}
function setMode(type, value) {
    form.flags = form.flags.filter(flag => flag !== type && flag !== 'auto_' + type);
    if (value !== 'off') form.flags.push(type);
    if (value === 'auto') form.flags.push('auto_' + type);
}
function recipientError(topic) {
    const field = 'recipients.' + topic.replace('.', '_');
    return Object.entries(form.errors).find(([key]) => key === field || key.startsWith(field + '.'))?.[1];
}
function save() {
    changes.submit(() => form.transform(({ flags, mail }) => {
        const context = { site: props.site, revision: props.settings.revision };
        if (!emails.value) return { ...context, features: flags };
        const recipients = Object.fromEntries(Object.entries(mail).map(([topic, value]) =>
            [topic.replace('.', '_'), value.split(/[,\n]/).map(email => email.trim()).filter(Boolean)]));
        return { ...context, recipients };
    }).post(props.url, { preserveScroll: true }));
}
</script>
<template>
    <main class="ss-page ss-settings">
        <SettingsHeader :title="emails ? __('Staff email recipients') : __('Storefront features')" :icon="emails ? 'mail' : 'shopping-cart'"
            :parent-url="settingsUrl" :parent-title="settingsTitle" :site :sites :scope="multisite ? sites[site] : ''" @site="value => router.get(url, { site: value })">
            <Button type="submit" form="ss-features" variant="primary" :text="__('Save changes')" :disabled="!form.isDirty || form.processing" />
        </SettingsHeader>
        <SettingsErrors :errors="form.errors" />
        <form id="ss-features" class="ss-stack" @submit.prevent="save">
            <CardPanel v-if="!emails">
                <div class="ss-stack">
                <CheckboxGroup v-model="form.flags"><Checkbox v-for="(label, key) in flags" :key="key" :label="__(label)" :value="key" /></CheckboxGroup>
                <Field v-for="(label, type) in recommendationTypes" :key="type" :label="__(label)">
                    <Select :id="'ss-recommendation-' + type" :model-value="mode(type)" :options="modes" option-label="label" option-value="value" @update:model-value="value => setMode(type, value)" />
                </Field>
                </div>
            </CardPanel>
            <CardPanel v-else :subheading="__('Separate addresses with commas. Leave a topic empty to turn off its emails.')">
                <div class="ss-stack"><Field v-for="(label, key) in topics" :key="key" :label="__(label)" :error="recipientError(key)">
                    <Input v-model="form.mail[key]" type="text" inputmode="email" />
                </Field></div>
            </CardPanel>
        </form>
    </main>
</template>
