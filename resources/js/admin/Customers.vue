<script setup>
import Listing from './Listing.vue';
import { Link } from '@statamic/cms/inertia';
defineProps({ results: Object, filters: Object, listUrl: String, sites: Object });
</script>
<template>
    <Listing
        :title="__('Customers')"
        icon="user-avatar"
        :results
        :filters
        :sites
        single-site-scope
        :list-url
        :tabs="[
            { value: '', label: 'Registered accounts' },
            { value: 'guests', label: 'Previous guests', empty: 'No previous guest customers' },
        ]"
        :search-label="__('Search customer name or email')"
        :empty="__('No registered customers yet')"
        :empty-hint="__('Registered customers for this site will appear here.')"
    >
        <ul class="ss-customer-list">
            <li v-for="contact in results.data" :key="contact.url">
                <Link :href="contact.url"
                    ><strong>{{ contact.name || contact.email }}</strong
                    ><small v-if="contact.name !== contact.email">{{ contact.email }}</small></Link
                >
            </li>
        </ul></Listing
    >
</template>
