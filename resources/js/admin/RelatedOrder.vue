<script setup>
import { Button, Icon } from '@statamic/cms/ui';

defineProps({ item: Object, readOnly: Boolean });
defineEmits(['removed']);
</script>

<template>
    <div class="ss-related-order">
        <a v-if="item.edit_url" :href="item.edit_url" target="_blank" rel="noopener">{{ __('Order') }} {{ item.title }} <Icon name="arrow-right-box" aria-hidden="true" /></a>
        <span v-else>{{ __('Order unavailable') }}</span>
        <Button v-if="!readOnly" size="sm" type="button" variant="ghost" :text="__('Remove')" @click="$emit('removed')" />
    </div>
</template>

<style scoped>
a { display: inline-flex; gap: .35rem; align-items: center; }
a svg { width: .85rem; height: .85rem; }
</style>
