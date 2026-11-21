<script setup>
import { useForm } from '@statamic/cms/inertia';
import { Button, Card, Textarea } from '@statamic/cms/ui';

const props = defineProps({ activity: Object, order: Object, noteUrl: String, noteRequestId: String });
const note = useForm({ message: '', request_id: '' });
function save() {
    note.request_id = props.noteRequestId;
    note.post(props.noteUrl, { preserveScroll: true, onSuccess: () => note.reset() });
}
</script>

<template>
    <Card id="order-activity" inset>
        <h2 class="ss-section-title">{{ __('Order activity') }}</h2>
        <div class="ss-body">
            <form v-if="noteUrl" class="ss-note-form" @submit.prevent="save">
                <label for="ss-private-note">{{ __('Private note') }}</label>
                <Textarea id="ss-private-note" v-model="note.message" :rows="2" maxlength="2000" required
                    :placeholder="__('Leave a note for store staff')" />
                <p v-for="error in note.errors" :key="error" role="alert">{{ error }}</p>
                <Button type="submit" size="sm" :text="__('Add private note')" :disabled="note.processing || !note.message.trim()" />
            </form>
            <ol class="ss-activity">
                <li v-for="entry in activity.data" :key="entry.id">
                    <small>{{ entry.kind === 'note' ? __('Private note · ') : '' }}{{ entry.author ? entry.author + ' · ' : '' }}{{ entry.date }}</small>
                    <p>{{ entry.message }}</p>
                </li>
                <li v-if="!activity.next_page_url">
                    <small>{{ order.date }} · {{ order.time }}</small>
                    <p>{{ __('Order placed.') }}</p>
                </li>
            </ol>
            <div v-if="activity.prev_page_url || activity.next_page_url" class="ss-actions">
                <Button v-if="activity.prev_page_url" :href="activity.prev_page_url" size="sm" :text="__('Newer activity')" />
                <Button v-if="activity.next_page_url" :href="activity.next_page_url" size="sm" :text="__('Older activity')" />
            </div>
        </div>
    </Card>
</template>
