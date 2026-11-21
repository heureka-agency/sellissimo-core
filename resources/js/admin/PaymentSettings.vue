<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Link, router, useForm, usePage } from '@statamic/cms/inertia';
import { Alert, Button, Card, Field, Heading, Input, Select, Switch } from '@statamic/cms/ui';
import SettingsHeader from './SettingsHeader.vue';
import SettingsErrors from './SettingsErrors.vue';
import { useSettingsChanges } from './useSettingsChanges';
const page = usePage();

const props = defineProps({
    site: String, sites: Object, multisite: Boolean, preferences: Object, methods: Array, warnings: Array,
    url: String, saveUrl: String, settingsUrl: String, shopUrl: String, shippingUrl: String, csrf: String,
    canConnect: Boolean, canEdit: Boolean,
});
const form = useForm({
    revision: props.preferences.revision, inherit: props.preferences.inherited,
    methods: props.preferences.methods.filter(handle => props.methods.some(method => method.handle === handle)), modes: { ...props.preferences.modes },
    presentation: props.preferences.presentationOverride ?? '',
});
const slots = { deployment: __('Hosting configuration'), test: __('Test'), live: __('Live') };
const fieldLabels = { key: __('API key'), secret: __('Secret key'), publishable_key: __('Publishable key (for embedded checkout)'), webhook_secret: __('Webhook signing secret') };
const statuses = reactive({});
const editor = reactive({ handle: '', mode: 'test', credentials: {}, errors: {} });
const busy = ref(false);
function setEnabled(handle, checked) {
    form.methods = checked ? [...new Set([...form.methods, handle])] : form.methods.filter(value => value !== handle);
}
function selection(method) {
    return method.locked ? 'deployment' : (form.modes[method.handle] || 'deployment');
}
function chooseSlot(method, mode) {
    form.modes[method.handle] = mode;
}
function edit(method, mode) {
    if (Object.values(editor.credentials).some(Boolean) && !window.confirm(__('Discard the credential you entered?'))) return;
    Object.assign(editor, { handle: method?.handle || '', mode, credentials: {}, errors: {} });
}
const dirty = computed(() => form.isDirty || Object.values(editor.credentials).some(Boolean));
const changes = useSettingsChanges(() => dirty.value);
onBeforeUnmount(() => { editor.credentials = {}; });
function save() {
    if (editor.handle && Object.values(editor.credentials).some(Boolean)) return;
    changes.submit(() => form.post(props.saveUrl, {
        preserveScroll: true,
        onSuccess() { form.revision = props.preferences.revision; form.defaults(); },
    }));
}
function missing(method) {
    const keys = method.handle === 'stripe' ? ['secret', 'webhook_secret'] : ['key'];
    if (method.handle === 'stripe' && (form.presentation || props.preferences.presentation) === 'embedded') keys.push('publishable_key');
    return keys.filter(key => !method.connections[selection(method)].fields.includes(key));
}
function options(method) {
    return Object.entries(slots)
        .filter(([key]) => key === 'deployment' || (!method.locked && method.connections[key]?.configured))
        .map(([value, label]) => ({ value, label }));
}
async function connection(method, mode, action) {
    busy.value = true;
    editor.errors = {};
    statuses[method.handle] = '';
    try {
        const response = await fetch(method.url, {
            method: 'POST', headers: { 'X-CSRF-TOKEN': props.csrf, 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ mode, action, revision: method.connections[mode].revision, credentials: action === 'replace' ? editor.credentials : {} }),
        });
        const result = await response.json();
        if (!response.ok) {
            editor.errors = result.errors || {};
            throw new Error(result.message || __('Unable to verify the payment connection.'));
        }
        Object.assign(editor, { handle: '', credentials: {}, errors: {} });
        statuses[method.handle] = { variant: 'success', message: result.message };
        changes.submit(() => router.reload({ only: ['methods', 'warnings'], preserveScroll: true }));
    } catch (error) {
        statuses[method.handle] = { variant: 'error', message: error.message };
    } finally { busy.value = false; }
}
</script>

<template>
    <main class="ss-page ss-settings">
        <SettingsHeader :title="__('Payments')" icon="credit-card" :parent-url="settingsUrl" :site :sites :scope="multisite ? sites[site] : ''"
            @site="value => router.get(url, { site: value })">
            <Button v-if="canEdit" type="button" variant="primary" :text="__('Save changes')"
                :disabled="!form.isDirty || form.processing || busy || Object.values(editor.credentials).some(Boolean)" @click="save" />
        </SettingsHeader>
        <SettingsErrors :errors="page.props.errors" />
        <Alert v-if="warnings.length" variant="warning"><Heading :text="__('Payment setup')" />
            <ul><li v-for="warning in warnings" :key="warning">{{ warning }}</li></ul>
        </Alert>
        <form class="ss-stack" @submit.prevent="save">
            <Card class="ss-form">
                <h2>{{ __('Buyer payment methods') }}</h2>
                <Field id="inherit-payments" :label="__('Use payment methods from hosting configuration')">
                    <Switch id="inherit-payments" v-model="form.inherit" :disabled="!canEdit || preferences.methodsLocked" />
                </Field>
                <p v-if="preferences.methodsLocked">{{ __('Hosting controls the enabled payment methods.') }}</p>
                <Field v-for="method in methods" :id="'enable-' + method.handle" :key="method.handle" :label="method.name">
                    <Switch :id="'enable-' + method.handle" :model-value="(form.inherit ? (preferences.hostingMethods || preferences.methods) : form.methods).includes(method.handle)"
                        :disabled="form.inherit || !canEdit" @update:model-value="value => setEnabled(method.handle, value)" />
                </Field>
                <p v-if="methods.some(m => m.handle === 'pay_on_delivery')">
                    {{ __('Pay on delivery is available for physical items with an eligible delivery service.') }}
                    <Link v-if="shippingUrl" :href="shippingUrl">{{ __('Manage delivery services') }}</Link>
                </p>
            </Card>
            <Card v-for="method in methods.filter(m => m.webhook)" :key="method.handle" class="ss-form">
                <h2>{{ method.name }}</h2>
                <Field :id="'connection-' + method.handle" :label="__('Connection for new payments')">
                    <Select :id="'connection-' + method.handle" :model-value="selection(method)" :options="options(method)"
                        :disabled="!canEdit || method.locked" @update:model-value="mode => chooseSlot(method, mode)" />
                </Field>
                <p v-if="method.locked">{{ __('Hosting manages this connection.') }}</p>
                <Field v-if="method.handle === 'stripe'" id="stripe-presentation" :label="__('Checkout presentation')">
                    <Select id="stripe-presentation" v-model="form.presentation" :disabled="!canEdit || preferences.presentationLocked"
                        :options="[{ value: '', label: __('Hosting configuration') }, { value: 'hosted', label: __('Hosted checkout') }, { value: 'embedded', label: __('Embedded checkout') }]" />
                </Field>
                <p v-if="method.handle === 'stripe' && preferences.presentationLocked">{{ __('Hosting controls the checkout presentation.') }}</p>
                <dl class="ss-payment-evidence">
                    <template v-if="missing(method).length"><dt>{{ __('Missing credentials') }}</dt><dd>{{ missing(method).map(key => fieldLabels[key]).join(', ') }}</dd></template>
                    <dt>{{ __('Account mode') }}</dt><dd>{{ method.connections[selection(method)].configured ? (slots[method.connections[selection(method)].mode] || __('Unknown')) : __('Not connected') }}</dd>
                    <dt>{{ __('Last connection check') }}</dt><dd><template v-if="method.connections[selection(method)].checked">{{ method.connections[selection(method)].passed ? __('Verified') : __('Failed') }} · {{ method.connections[selection(method)].checked }}</template><template v-else>{{ __('Not checked yet') }}</template></dd>
                    <dt>{{ __('Last verified callback') }}</dt><dd>{{ method.connections[selection(method)].callback || __('Not received yet') }}</dd>
                    <dt>{{ __('Last paid checkout on this site') }}</dt><dd>{{ method.connections[selection(method)].checkout || __('No completed checkout recorded') }}</dd>
                    <dt>{{ __('Callback URL') }}</dt><dd><code>{{ method.webhook }}</code></dd>
                </dl>
                <p>{{ method.handle === 'stripe' ? __('Register the callback URL in Stripe and save its signing secret here.') : __('Mollie receives the callback URL with each payment.') }}</p>
                <a v-if="shopUrl && method.connections[selection(method)].mode === 'test'" :href="shopUrl" target="_blank" rel="noopener">{{ __('Open the storefront to place a test order') }}</a>
                <template v-if="canConnect">
                    <Button v-if="method.connections[selection(method)].configured" type="button" :disabled="busy"
                        :text="__('Check connection')" @click="connection(method, selection(method), 'check')" />
                    <details v-if="!method.locked">
                        <summary>{{ __('Manage shared credentials') }} · {{ __('All sites') }}</summary>
                        <p>{{ __('Replacing a connection affects new payments on every site using that connection. Earlier payments keep their original account.') }}</p>
                        <div v-for="mode in ['test','live']" :key="mode" class="ss-payment-slot">
                            <strong>{{ slots[mode] }}</strong>
                            <span>{{ method.connections[mode].account || __('No verified account') }}</span>
                            <Button type="button" :disabled="busy" :text="method.connections[mode].configured ? __('Replace credentials') : __('Connect account')"
                                @click="edit(method, mode)" />
                            <div v-if="editor.handle === method.handle && editor.mode === mode" class="ss-form">
                                <Field v-for="key in (method.handle === 'stripe' ? ['secret', 'publishable_key', 'webhook_secret'] : ['key'])"
                                    :id="method.handle + '-' + mode + '-' + key" :key="key" :label="fieldLabels[key]" :error="editor.errors[key]?.[0]">
                                    <Input :id="method.handle + '-' + mode + '-' + key" v-model="editor.credentials[key]" type="password"
                                        autocomplete="new-password" spellcheck="false" />
                                </Field>
                                <div class="ss-actions">
                                    <Button type="button" :disabled="busy" :text="__('Verify and save credentials')" @click="connection(method, mode, 'replace')" />
                                    <Button type="button" :disabled="busy" :text="__('Cancel')" @click="edit(null, 'test')" />
                                </div>
                            </div>
                        </div>
                    </details>
                </template>
                <Alert v-if="statuses[method.handle]" :variant="statuses[method.handle].variant" live>{{ statuses[method.handle].message }}</Alert>
            </Card>
        </form>
    </main>
</template>

<style scoped>
.ss-payment-evidence { display: grid; grid-template-columns: minmax(10rem, auto) 1fr; gap: .35rem 1rem; }
.ss-payment-evidence dd { overflow-wrap: anywhere; }
.ss-payment-slot { display: flex; flex-wrap: wrap; align-items: center; gap: .65rem; margin-block: 1rem; }
.ss-payment-slot > .ss-form { flex-basis: 100%; }
summary { cursor: pointer; }
@media (max-width: 40rem) { .ss-payment-evidence { grid-template-columns: 1fr; } .ss-payment-evidence dd { margin-bottom: .5rem; } }
</style>
