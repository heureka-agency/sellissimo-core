<script setup>
import { Card } from '@statamic/cms/ui';
defineProps({ rows: Array });
</script>
<template>
    <Card v-if="rows?.length"><dl class="ss-stack">
        <div v-for="(row, index) in rows" :key="index"><dt>{{ row.label }}</dt>
            <dd><a v-if="row.url" :href="row.url" target="_blank" rel="noopener noreferrer">{{ row.value }}</a><span v-else>{{ row.value }}</span></dd>
        </div>
    </dl></Card>
</template>
