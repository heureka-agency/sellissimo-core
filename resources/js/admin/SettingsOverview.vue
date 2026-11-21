<script setup>
import { computed } from 'vue';
import { Link, router } from '@statamic/cms/inertia';
import { Alert, Card, CardPanel, Heading, Icon, Subheading } from '@statamic/cms/ui';
import SettingsHeader from './SettingsHeader.vue';

const props = defineProps({ site: String, sites: Object, multisite: Boolean, sections: Array, summary: Object, overviewUrl: String });
const groups = computed(() => [...new Set(props.sections.map(section => section.group))]);
const facts = { currency: 'Selling currency', storefront: 'Storefront', payments: 'Payment methods', delivery: 'Delivery coverage' };
const icons = {
    details: 'cog', catalog: 'collections', checkout: 'shopping-cart', payments: 'credit-card',
    shipping: 'package-box-crate', taxes: 'money-cashier-calculator', orders: 'add-entry', emails: 'mail', system: 'link',
};
</script>
<template>
    <main class="ss-page ss-settings">
        <SettingsHeader :title="__('Store settings')" :site :sites :scope="multisite ? sites[site] : ''"
            @site="value => router.get(overviewUrl, { site: value })" />
        <div class="settings-sections">
            <Card v-if="Object.keys(facts).some(key => summary[key])">
                <dl class="ss-settings-facts">
                    <template v-for="(label, key) in facts" :key="key">
                        <div v-if="summary[key]"><dt>{{ __(label) }}</dt><dd>{{ summary[key] }}</dd></div>
                    </template>
                </dl>
            </Card>
            <Alert v-if="summary.tasks.length" variant="warning"><Heading :text="__('Finish setting up your store')" />
                <ul class="ss-settings-list"><li v-for="task in summary.tasks" :key="task.message">
                    <Link v-if="task.url" :href="task.url">{{ task.message }}</Link><span v-else>{{ task.message }}</span>
                </li></ul>
            </Alert>
            <CardPanel v-for="group in groups" :key="group" :heading="group" :level="2">
                <div class="settings-links">
                    <Link v-for="item in sections.filter(section => section.group === group)" :key="item.handle" :href="item.url">
                        <Icon :name="icons[item.handle] || 'cog'" aria-hidden="true" />
                        <span><Heading size="base" :text="item.title" /><Subheading>{{ item.description }}</Subheading></span>
                    </Link>
                </div>
            </CardPanel>
        </div>
    </main>
</template>
<style scoped>
.settings-sections { display: grid; gap: 1.25rem; }
.settings-links { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr)); gap: .25rem; }
.settings-links a { display: flex; align-items: flex-start; gap: .75rem; min-width: 0; padding: .8rem; border-radius: .45rem; }
.settings-links a:is(:hover, :focus-visible) { background: var(--ss-tint); }
.settings-links svg { width: 1.2rem; height: 1.2rem; flex: none; margin-top: .15rem; color: var(--ss-muted); }
</style>
