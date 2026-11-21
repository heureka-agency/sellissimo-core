<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@statamic/cms/inertia';
import { Alert, Button, Card, Checkbox, CheckboxGroup, PublishContainer } from '@statamic/cms/ui';
import SettingsHeader from './SettingsHeader.vue';
import SettingsErrors from './SettingsErrors.vue';
import { useSettingsChanges } from './useSettingsChanges';
import CurrencySettings from './CurrencySettings.vue';
import TaxPreview from './TaxPreview.vue';

const props = defineProps({
    sites: Object, sectionUrl: String, site: String, siteName: String, multisite: Boolean, section: String, title: String, description: String,
    overviewUrl: String, saveUrl: String, blueprint: Object, values: [Object, Array], meta: [Object, Array],
    sources: [Object, Array], revisions: Object, links: Array, issues: Array, diagnostics: [Object, Array], installed: Boolean,
    csrf: String, currencyUrl: String, checkUrl: String, testRecipient: String, taxPreviewUrl: String, taxCountries: [Array,Object], taxClasses: [Array,Object],
});
const copy = value => JSON.parse(JSON.stringify(value));
const form = ref({}), resets = ref([]), errors = ref({}), notice = ref(''), busy = ref(false);
const changed = computed(() => Object.fromEntries(Object.entries(form.value).filter(([key, value]) =>
    props.sources[key] && !props.sources[key].readonly && !resets.value.includes(key) && JSON.stringify(value) !== JSON.stringify(props.values[key]))));
const dirty = computed(() => resets.value.length > 0 || Object.keys(changed.value).length > 0);
const canEdit = computed(() => Object.values(props.sources).some(field => !field.readonly));
const savedFields = computed(() => Object.entries(props.sources).filter(([, field]) => field.saved && !field.readonly));
const formKey = computed(() => [props.site, props.section, props.revisions.site, props.revisions.shared].join('-'));
watch(() => [props.section, props.site, props.values], () => {
    form.value = copy(props.values);
    resets.value = [];
    errors.value = {};
}, { immediate: true });
watch(() => [props.section, props.site], () => { notice.value = ''; });
const changes = useSettingsChanges(() => dirty.value);
async function submit(url, method, payload) {
    const result = await fetch(url, {
        method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': props.csrf },
        body: JSON.stringify(payload),
    });
    const data = await result.json().catch(() => ({}));
    if (!result.ok) {
        errors.value = data.errors || { form: [data.message || __('The request failed. Try again.')] };
        return null;
    }
    return data;
}
function refresh() {
    changes.submit(() => router.reload({ preserveScroll: true }));
}
async function act(check = false) {
    if (busy.value) return;
    busy.value = true;
    errors.value = {};
    notice.value = '';
    try {
        const data = check ? await submit(props.checkUrl, 'POST', {}) : await submit(props.saveUrl, 'PATCH', {
            changes: changed.value, reset: resets.value, revisions: props.revisions,
        });
        if (!data) return;
        notice.value = check ? data.message : __('Settings saved.');
        if (!check || (!dirty.value && props.section === 'system')) refresh();
    } catch {
        errors.value = { form: [__('Could not reach the store. Your changes are still here.')] };
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <main class="ss-page ss-settings">
        <SettingsHeader :title :parent-url="overviewUrl" :site :sites :scope="multisite ? siteName : ''"
            @site="value => router.get(sectionUrl, { site: value })">
            <Button v-if="canEdit" variant="primary" :disabled="busy || !dirty" :text="__('Save changes')" @click="act()" />
        </SettingsHeader>
        <p class="ss-settings-intro">{{ description }}</p>
        <SettingsErrors :errors />
        <Alert v-if="notice" variant="success" role="status">{{ notice }}</Alert>
        <Alert v-if="!installed" variant="warning" :text="__('Run php please sellissimo:install to finish installing the settings tables.')" />
        <CurrencySettings v-if="currencyUrl" :site="site" :endpoint="currencyUrl" />
        <PublishContainer v-if="Object.keys(sources).length" :key="formKey" v-model="form" :blueprint="blueprint"
            :name="'sellissimo-settings-' + section" :meta="meta" :track-dirty-state="false" :errors="errors" :read-only="!canEdit" />
        <Card v-if="section === 'details'" class="ss-settings-block">
            <h2>{{ __('Receipt preview') }}</h2>
            <address class="ss-settings-address"><strong>{{ form.trading_name || siteName }}</strong>
                <span v-if="form.address_line">{{ form.address_line }}</span>
                <span v-if="form.address_city || form.address_region || form.address_postcode || form.address_country">{{ [form.address_city, form.address_region, form.address_postcode, form.address_country].filter(Boolean).join(', ') }}</span>
                <span v-if="form.contact_email">{{ form.contact_email }}</span>
            </address>
        </Card>
        <details v-if="savedFields.length" class="ss-settings-block"><summary>{{ __('Restore defaults') }}</summary>
            <p>{{ __('Select the saved values to reset, then save changes.') }}</p>
            <CheckboxGroup v-model="resets"><Checkbox v-for="[key, field] in savedFields" :key="key" :value="key" :label="field.label" /></CheckboxGroup>
        </details>
        <ul v-if="issues.length" class="ss-settings-list ss-settings-block"><li v-for="issue in issues" :key="issue.message">
            <Link v-if="issue.url" :href="issue.url">{{ issue.message }}</Link><span v-else>{{ issue.message }}</span>
        </li></ul>
        <Card v-if="Object.keys(diagnostics).length" class="ss-settings-block">
            <dl class="ss-settings-status"><div v-for="(value, label) in diagnostics" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div></dl>
        </Card>
        <TaxPreview v-if="taxPreviewUrl" :url="taxPreviewUrl" :countries="taxCountries" :classes="taxClasses" :csrf="csrf" />
        <div v-if="checkUrl" class="ss-settings-block">
            <p v-if="testRecipient">{{ __('Send a test to your staff address, :email.', {email: testRecipient}) }}</p>
            <Button :disabled="busy" :text="testRecipient ? __('Send test email') : __('Check private file storage')" @click="act(true)" />
        </div>
        <Card v-if="links.length" inset class="ss-settings-block"><ul class="ss-settings-menu"><li v-for="item in links" :key="item.url">
            <Link :href="item.url"><span>{{ item.title }} <small v-if="item.shared">{{ __('All sites') }}</small></span><span aria-hidden="true">›</span></Link>
        </li></ul></Card>
        <Alert v-if="section === 'shipping' && !links.length" :text="__('Delivery rules come from installed shipping extensions. An administrator with access to all sites can manage shared delivery zones.')" />
    </main>
</template>
