<script setup>
import { computed, ref, watch } from 'vue';
import { Alert, Button, Card, Checkbox, Field } from '@statamic/cms/ui';
import StoreChoice from './StoreChoice.vue';
import CurrencyOrderPreview from './CurrencyOrderPreview.vue';

const props = defineProps({ site: { type: String, required: true }, endpoint: { type: String, required: true } });
const state = ref(null);
const selected = ref('');
const acknowledge = ref(false);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const status = ref('');
let generation = 0;

const changed = computed(() => selected.value && selected.value !== state.value?.active);
const blocked = computed(() => !state.value?.preview || state.value.preview.blocking.length > 0
    || (state.value.preview.groups.length > 0 && !acknowledge.value));

async function load(target = '') {
    const current = ++generation;
    const url = new URL(props.endpoint, window.location.href);
    url.searchParams.set('site', props.site);
    if (target) url.searchParams.set('target', target);
    loading.value = true;
    error.value = '';
    acknowledge.value = false;
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(__('Unable to load selling currency settings.'));
        const data = await response.json();
        if (current !== generation) return;
        state.value = data;
        selected.value = target || data.proposed || data.active || '';
    } catch (failure) {
        if (current === generation) error.value = failure.message;
    } finally {
        if (current === generation) loading.value = false;
    }
}
function select(target) {
    if (!target) return;
    selected.value = target;
    status.value = '';
    load(target);
}
async function save(action) {
    if (loading.value || saving.value) return;
    saving.value = true;
    error.value = '';
    status.value = '';
    try {
        const response = await fetch(props.endpoint, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': state.value.token },
            body: JSON.stringify({ site: props.site, action, target: selected.value, acknowledge: acknowledge.value, digest: state.value.preview?.digest, revision: state.value.revision }),
        });
        if (!response.ok) {
            const message = await response.json();
            throw new Error(Object.values(message.errors || {}).flat()[0] || message.message || __('Unable to save selling currency.'));
        }
        status.value = action === 'prepare' ? __('The proposed currency is available in price editors.') : __('Currency settings saved.');
        await load();
    } catch (failure) {
        error.value = failure.message;
    } finally {
        saving.value = false;
    }
}
watch(() => [props.endpoint, props.site], () => { state.value = null; selected.value = ''; status.value = ''; load(); }, { immediate: true });
</script>

<template>
    <Card class="ss-form" :aria-busy="loading || saving">
        <h2>{{ __('Selling currency') }}</h2>
        <Alert v-if="error" variant="error"><span>{{ error }}</span> <Button size="sm" :text="__('Reload')" @click="load()" /></Alert>
        <template v-if="state">
            <p>{{ state.active ? __('Current currency: :currency', { currency: state.active }) : __('Choose the first selling currency for this site.') }}
                <span>{{ state.overridden ? __('Saved for this site.') : __('Inherited from configuration.') }}</span></p>
            <p v-if="state.locked">{{ __('The selling currency is managed by your hosting configuration.') }}</p>
            <Field :label="__('Currency')" id="sellissimo-selling-currency">
                <StoreChoice id="sellissimo-selling-currency" :model-value="selected" :options="state.options" :read-only="!state.installed || state.locked || saving"
                    :placeholder="__('Search by currency name or code')" @update:model-value="select" />
            </Field>
            <p v-if="!loading && state.example">{{ __('Example price') }} · {{ state.example }}</p>
            <p v-if="state.proposed">{{ __('Preparing prices in :currency', { currency: state.proposed }) }}</p>
            <template v-if="!loading && state.preview && selected && (changed || state.proposed)">
                <p>{{ __(':priced of :count published products and variants have a price in :currency.', { priced: state.preview.priced, count: state.preview.published, currency: selected }) }}</p>
                <p>{{ __(':eligible products and variants have a configured way to complete checkout.', { eligible: state.preview.eligible }) }}</p>
                <Alert v-if="state.preview.blocking.length" variant="warning"><ul><li v-for="problem in state.preview.blocking" :key="problem">{{ problem }}</li></ul></Alert>
                <details v-for="group in state.preview.groups" :key="group.label"><summary>{{ group.label }} ({{ group.count }})</summary>
                    <ul><li v-for="(item,i) in group.items" :key="i"><a v-if="item.url" :href="item.url">{{ item.label }}</a><span v-else>{{ item.label }}</span></li></ul>
                    <p v-if="group.items.length === 50 && group.count > 50">{{ __('Showing the first 50 records.') }}</p>
                </details>
                <nav v-if="state.preview.blocking.length" :aria-label="__('Resolve checkout availability')" class="flex flex-wrap gap-2">
                    <a v-for="action in state.preview.actions" :key="action.handle" :href="action.url">{{ action.title }}</a>
                </nav>
                <Checkbox v-if="changed && state.preview.groups.length && !state.locked" v-model="acknowledge" :label="__('I have reviewed these items and accept the availability shown above.')" />
            </template>
            <CurrencyOrderPreview v-if="!loading && state.preview?.sampleSku" :site :endpoint :currency="selected"
                :first-sku="state.preview.sampleSku" :countries="state.countries" :default-country="state.defaultCountry" />
            <div v-if="state.installed && !state.locked" class="flex flex-wrap gap-2">
                <Button v-if="changed && selected !== state.proposed" :text="__('Prepare prices')" :disabled="loading || saving || !!error" @click="save('prepare')" />
                <Button v-if="changed" variant="primary" :text="__('Activate currency')" :disabled="loading || saving || !!error || blocked" @click="save('activate')" />
                <Button v-if="state.proposed" :text="__('Cancel preparation')" :disabled="loading || saving" @click="save('cancel')" />
                <Button v-if="state.overridden && state.inherited && selected !== state.inherited" :text="__('Review inherited currency')" :disabled="saving" @click="select(state.inherited)" />
                <Button v-if="state.overridden && selected === state.inherited" :text="__('Restore inherited currency')" :disabled="loading || saving || !!error || (changed && blocked)" @click="save('reset')" />
            </div>
        </template>
        <p v-if="loading" role="status">{{ __('Checking prices and availability…') }}</p>
        <Alert v-else-if="status" variant="success" role="status">{{ status }}</Alert>
    </Card>
</template>
