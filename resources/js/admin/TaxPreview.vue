<script setup>
import { ref, reactive, watch } from 'vue';
import { Alert, Button, Card, Input, Field } from '@statamic/cms/ui';
import StoreChoice from './StoreChoice.vue';
const props = defineProps({ url: String, countries: Object, classes: Object, csrf: String });
const form = reactive({ amount: '100', country: '', region: '', postal_code: '', tax_class: 'standard' });
const choices = options => Object.entries(options).map(([value, label]) => ({ value, label }));
const result = ref(null), error = ref(''), busy = ref(false);
let request = 0;
watch(form, () => { request++; result.value = null; error.value = ''; busy.value = false; });
async function preview() {
    const version = ++request;
    busy.value = true;
    result.value = null;
    error.value = '';
    try {
        const response = await fetch(props.url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': props.csrf }, body: JSON.stringify(form) });
        const data = await response.json();
        if (version !== request) return;
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
        result.value = data;
    } catch (failure) {
        if (version === request) error.value = failure.message || __('The sample could not be calculated.');
    } finally { if (version === request) busy.value = false; }
}
</script>
<template>
    <Card class="ss-settings-block"><form class="ss-form" @submit.prevent="preview">
        <h2>{{ __('Preview tax on one item') }}</h2>
        <Field :label="__('Price in the selling currency')"><Input v-model="form.amount" required /></Field>
        <Field :label="__('Tax class')"><StoreChoice v-model="form.tax_class" :options="choices(classes)" /></Field>
        <Field :label="__('Country')"><StoreChoice v-model="form.country" :options="choices(countries)" /></Field>
        <Field :label="__('State, province or region')"><Input v-model="form.region" /></Field>
        <Field :label="__('Postal code')"><Input v-model="form.postal_code" /></Field>
        <div><Button type="submit" :disabled="busy || !form.country" :text="__('Calculate sample')" /></div>
        <Alert v-if="error" variant="error">{{ error }}</Alert>
        <Alert v-if="result" role="status">{{ result.basis }} · {{ result.inclusive ? __('Tax included') : __('Tax added') }} {{ result.tax }} · {{ __('Total') }} {{ result.total }}</Alert>
    </form></Card>
</template>
