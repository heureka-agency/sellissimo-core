<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link, useForm } from '@statamic/cms/inertia';
import { Card, Button, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Field, Select } from '@statamic/cms/ui';
import SiteFilter from './SiteFilter.vue';
import OperationsPage from './OperationsPage.vue';
const props = defineProps({
    listUrl: String, recent: Array,
    sites: Object,
    collections: Array,
    site: String,
    preview: Object,
    previewUrl: String,
    applyUrl: String,
    templateUrl: String,
    exportUrl: String,
});
const form = useForm({ file: null, site: props.site, collection: props.collections[0] });
const downloadUrl = computed(() => props.exportUrl + '?' + new URLSearchParams({ site: form.site, collection: form.collection }));
const apply = useForm({ token: null });
function resourceLabel(resource) {
    if (resource.startsWith('image:')) return __('New image') + ' · ' + resource.slice(6);
    const [, taxonomy, term] = resource.split(':');
    return __(taxonomy === 'sellissimo_categories' ? 'New category' : 'New tag') + ' · ' + term;
}
const running = ref(false);
onBeforeUnmount(() => { running.value = false; });
function importRows() {
    running.value = true;
    apply.token = props.preview.token;
    apply.post(props.applyUrl, { preserveScroll: true, onSuccess: (page) => {
        if (running.value && page.props.preview?.token === apply.token && page.props.preview.remaining > 0) {
            const token = apply.token;
            setTimeout(() => { if (running.value && props.preview?.token === token) importRows(); }, 80);
        } else {
            running.value = false;
        }
    }, onError: () => running.value = false });
}
</script>
<template>
    <OperationsPage :title="__('Catalog import and export')" icon="collections">
        <template #action
            ><a class="ss-order-link" :href="templateUrl" download>{{ __('Download CSV template') }}</a></template
        >
        <Card
            ><form class="ss-form" @submit.prevent="form.post(previewUrl, { forceFormData: true })">
                <h2>{{ __('Product and variant CSV') }}</h2>
                <div class="ss-field-grid">
                    <SiteFilter :sites :model-value="form.site" @update:model-value="site => form.site = site" />
                    <Field id="import-collection" :label="__('Product collection')" :error="form.errors.collection">
                        <Select id="import-collection" v-model="form.collection" :options="collections" />
                    </Field>
                </div>
                <a class="ss-order-link" :href="downloadUrl + '&images=1'" download>{{ __('Export with product images (ZIP)') }}</a>
                <a class="ss-order-link" :href="downloadUrl" download>{{ __('Export current products and variants') }}</a>
                <label
                    >{{ __('Catalog CSV or ZIP with images') }}<input
                        type="file"
                        accept=".csv,.zip,text/csv,application/zip"
                        required
                        @change="form.file = $event.target.files[0]"
                /></label>
                <details class="ss-disclosure"><summary>{{ __('File requirements') }}</summary>
                <p class="ss-field-help">
                    {{ __('Match products by SKU. Give variant rows the same product_handle and distinct SKUs and variant names. Enter prices in the site currency. Leave a cell empty to keep its value or enter __CLEAR__ to clear an optional field. New entries start as drafts unless published is 1.') }}
                </p>
                <p class="ss-field-help">{{ __('CSV or ZIP files can contain up to 20,000 rows. Put images under images/ in the ZIP. Use JSON lists for categories, tags and gallery, and JSON objects for variant attributes and available stock per warehouse.') }}</p></details>
                <p v-for="message in form.errors" role="alert">{{ message }}</p>
                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        :text="__('Preview import')"
                        :disabled="form.processing"
                    />
                </div></form
        ></Card>
        <Card v-if="recent?.length"><h2>{{ __('Recent import previews') }}</h2>
            <ul><li v-for="job in recent" :key="job.id"><Link :href="listUrl + '?batch=' + job.id">{{ job.collection }} · {{ job.site }} · {{ job.created_at }} · {{ job.rows }} {{ __('rows') }}</Link></li></ul>
        </Card>
        <Card v-if="preview"
            ><h2>{{ __('Import preview') }}</h2>
            <p>
                {{ preview.total }} {{ __('rows ·') }} {{ preview.issues }} {{ __('conflicts ·') }} {{ preview.collection }} /
                {{ preview.site }}
            </p>
            <div class="ss-table-wrap">
                <Table class="ss-table">
                    <TableColumns>
                            <TableColumn>{{ __('Row') }}</TableColumn>
                            <TableColumn>{{ __('SKU') }}</TableColumn>
                            <TableColumn>{{ __('Change') }}</TableColumn>
                        </TableColumns>
                    <TableRows>
                        <TableRow v-for="row in preview.rows" :key="row.number">
                            <TableCell>{{ row.number }}</TableCell>
                            <TableCell>{{ row.sku }}</TableCell>
                            <TableCell>
                                {{ __(row.action) }} <small>{{ row.message }}</small>
                                <small v-for="resource in row.resources" :key="resource">{{ resourceLabel(resource) }}</small>
                                <small v-for="(count, location) in row.stock" :key="location">{{ location }} · {{ count.previous }} → {{ count.quantity }}</small>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>
            <nav v-if="preview.pages > 1" class="ss-actions">
                <Link v-if="preview.page > 1" :href="listUrl + '?batch=' + preview.token + '&preview_page=' + (preview.page - 1)">{{ __('Previous') }}</Link>
                <span>{{ preview.page }} / {{ preview.pages }}</span>
                <Link v-if="preview.page < preview.pages" :href="listUrl + '?batch=' + preview.token + '&preview_page=' + (preview.page + 1)">{{ __('Next') }}</Link>
            </nav>
            <p v-if="preview.groups > 0">{{ preview.groups - preview.remaining }} / {{ preview.groups }} {{ __('products applied') }}</p>
            <p v-for="error in apply.errors" role="alert">{{ error }}</p>
            <Button v-if="running" :text="__('Pause after this batch')" @click="running = false" />
            <Button v-if="!preview.complete" :text="preview.groups !== preview.remaining ? __('Resume import') : __('Apply import')" :disabled="!!preview.issues || apply.processing || running" @click="importRows"
        /></Card>
    </OperationsPage>
</template>
