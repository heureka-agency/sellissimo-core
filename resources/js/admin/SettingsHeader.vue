<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { Badge, Header, Icon } from '@statamic/cms/ui';
import SiteFilter from './SiteFilter.vue';

defineProps({ title: String, icon: { type: String, default: 'cog' }, parentUrl: String, parentTitle: String, site: String, sites: Object, scope: String });
defineEmits(['site']);
</script>
<template>
    <Head :title />
    <nav v-if="parentUrl" :aria-label="__('Breadcrumb')" class="ss-settings-back">
        <Link :href="parentUrl"><Icon name="chevron-left" aria-hidden="true" />{{ parentTitle || __('Store settings') }}</Link>
    </nav>
    <Header :title :icon>
        <template #actions><div class="settings-actions">
            <SiteFilter v-if="sites && Object.keys(sites).length > 1" :sites :model-value="site" @update:model-value="$emit('site', $event)" />
            <Badge v-else-if="scope" :text="scope" />
            <slot />
        </div></template>
    </Header>
</template>
<style scoped>
.ss-settings-back a, .settings-actions { display: flex; align-items: center; gap: .4rem; }
.ss-settings-back svg { width: .85rem; height: .85rem; }
.settings-actions { flex-wrap: wrap; gap: .6rem; }
</style>
