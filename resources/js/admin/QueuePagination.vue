<script setup>
import { Button } from '@statamic/cms/ui';

defineProps({ records: Object });
</script>

<template>
    <nav v-if="records?.data?.length && (records.total || records.prev_page_url || records.next_page_url)" class="ss-footer" :aria-label="__('Pagination')">
        <span v-if="records.from != null && records.to != null">
            {{ records.total == null
                ? __(':from–:to', { from: records.from, to: records.to })
                : __(':from–:to of :count', { from: records.from, to: records.to, count: records.total }) }}
        </span>
        <div v-if="records.prev_page_url || records.next_page_url" class="ss-actions">
            <Button v-if="records.prev_page_url" :href="records.prev_page_url" size="sm" :text="__('Previous')" />
            <Button v-if="records.next_page_url" :href="records.next_page_url" size="sm" :text="__('Next')" />
        </div>
    </nav>
</template>
