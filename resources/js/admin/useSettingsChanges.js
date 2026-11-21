import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { dirty, preferences } from '@statamic/cms/api';

// Use Statamic's navigation preference and its built-in link, browser back and tab-close protection.
export function useSettingsChanges(hasChanges) {
    const key = 'sellissimo-settings-' + useId();
    const submitting = ref(false);
    watch(() => !submitting.value && hasChanges(), changed => dirty.state(key, changed), { immediate: true, flush: 'sync' });
    onBeforeUnmount(() => dirty.remove(key));

    async function submit(visit) {
        submitting.value = true;
        await nextTick();
        try { visit(); } finally { submitting.value = false; }
    }
    function mayClose() {
        return !hasChanges() || !preferences.get('confirm_dirty_navigation', true) || window.confirm(__('statamic::messages.dirty_navigation_warning'));
    }
    return { submit, mayClose };
}
