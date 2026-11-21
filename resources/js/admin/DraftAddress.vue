<script setup>
import requirements from '../../address-requirements.json';
import { computed, useId } from 'vue';
import subdivisions from '../../postal-subdivisions.json';
import { Field, Input } from '@statamic/cms/ui';
import StoreChoice from './StoreChoice.vue';

const address = defineModel({ required: true });
const props = defineProps({
    prefix: String,
    countries: Array,
    requiredFields: Array,
    errors: Object,
    recipient: Boolean,
    delivery: Boolean,
});
const regionList = useId();
const required = computed(() => {
    const fields = [...(props.requiredFields || [])];
    if (props.delivery) {
        const profile = requirements[address.value.country] ?? ['street', 'city', 'postal_code'];
        fields.push(...profile.map(field => field === 'city_or_region' ? (address.value.region ? 'region' : 'city') : field));
    }
    return fields;
});
const fields = [
    ['company', 'Company', 'organization', 150],
    ['phone', 'Phone number', 'tel', 50],
    ['street', 'Street address', 'address-line1', 250],
    ['street2', 'Apartment, suite or floor', 'address-line2', 250],
    ['city', 'City', 'address-level2', 100],
    ['region', 'State or region', 'address-level1', 100],
    ['postal_code', 'Postal code', 'postal-code', 32],
];
</script>
<template>
    <div class="ss-body ss-form">
        <Field :id="prefix + '-country'" :label="__('Country')" :error="errors[prefix + '.country']">
            <StoreChoice
                :id="prefix + '-country'"
                v-model="address.country"
                :options="countries"
                :placeholder="__('Choose a country')"
                @update:model-value="address.region = ''"
            />
        </Field>
        <label v-if="recipient"
            >{{ __('Recipient name') }}<Input v-model="address.name" autocomplete="shipping name" maxlength="150"
        /></label>
        <div class="ss-field-grid">
            <label v-for="[key, label, autocomplete, max] in fields" :key="key"
                ><span>{{ __(label) }}<span v-if="required.includes(key)" aria-hidden="true"> *</span></span>
                <Input
                    v-model="address[key]"
                    :maxlength="max"
                    :list="key === 'region' ? regionList : undefined"
                    :required="required.includes(key)"
                    :type="key === 'phone' ? 'tel' : 'text'"
                    :autocomplete="(recipient ? 'shipping ' : 'billing ') + autocomplete"
                />
                <datalist v-if="key === 'region'" :id="regionList">
                    <option v-for="[value, label] in subdivisions[address.country] || []" :key="value" :value="value">{{ label }}</option>
                </datalist>
                <small v-if="errors[prefix + '.' + key]" role="alert">{{ errors[prefix + '.' + key] }}</small>
            </label>
        </div>
    </div>
</template>
