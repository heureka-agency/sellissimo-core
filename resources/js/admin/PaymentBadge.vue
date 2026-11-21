<script setup>
import { Badge } from '@statamic/cms/ui';
defineProps({ status: String });
const colors = {
    paid: 'green', refund_pending: 'amber', partially_refunded: 'violet', refund_due: 'red',
    pending: 'amber',
    awaiting_collection: 'amber',
    refunded: 'violet',
    expired: 'default',
};
</script>
<template>
    <Badge
        :color="colors[status] || 'default'"
        :text="__(status.charAt(0).toUpperCase() + status.slice(1).replaceAll('_', ' '))"
    />
</template>
