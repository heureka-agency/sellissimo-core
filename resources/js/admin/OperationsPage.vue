<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@statamic/cms/inertia';
import { Alert, Header, Icon } from '@statamic/cms/ui';
import QueuePagination from './QueuePagination.vue';

defineProps({ title: String, icon: String, records: Object, listing: Boolean, parentUrl: String, parentTitle: String });
const page = usePage();
const messages = computed(() => Object.values(page.props.errors || {}).flat().filter(Boolean));
</script>

<template>
    <Head :title />
    <div class="ss-page" :class="{ 'ss-operations': !listing }">
        <Link v-if="parentUrl" class="ss-back" :href="parentUrl"><Icon name="arrow-left" /> {{ parentTitle }}</Link>
        <Header :title :icon>
            <template v-if="$slots.action" #actions><div class="ss-actions"><slot name="action" /></div></template>
        </Header>
        <Alert v-if="messages.length" variant="error">
            <ul><li v-for="(message, index) in messages" :key="index">{{ message }}</li></ul>
        </Alert>
        <slot />
        <QueuePagination v-if="records?.last_page > 1" :records />
    </div>
</template>
