<script setup>
import { computed } from 'vue';
const props = defineProps({ trend: Object, from: String, to: String, currency: String });
const day = value => Date.parse(value + 'T00:00:00Z') / 86400000;
const length = computed(() => day(props.to) - day(props.from) + 1);
const series = (values, first) => {
    const points = new Map([[0, 0], [length.value - 1, 0]]);
    for (const [date, value] of Object.entries(values)) {
        const index = day(date) - day(first);
        for (const adjacent of [index - 1, index + 1]) {
            if (adjacent >= 0 && adjacent < length.value && !points.has(adjacent)) points.set(adjacent, 0);
        }
        points.set(index, value);
    }
    return Array.from(points).sort((a, b) => a[0] - b[0]);
};
const lines = computed(() => [series(props.trend.current, props.from), series(props.trend.previous, props.trend.previousFrom)]);
const scale = computed(() => {
    let min = 0, max = 0;
    for (const line of lines.value) for (const [, value] of line) { min = Math.min(min, value); max = Math.max(max, value); }
    return { min, max };
});
const y = value => 112 - (value - scale.value.min) / (scale.value.max - scale.value.min || 1) * 104;
const coordinates = line => line.map(([index, value]) => [6 + 588 * index / (length.value - 1 || 1), y(value)].join(',')).join(' ');
const money = value => new Intl.NumberFormat(document.documentElement.lang || undefined, {
    style: 'currency', currency: props.currency, minimumFractionDigits: props.trend.digits, maximumFractionDigits: props.trend.digits,
}).format(value / 10 ** props.trend.digits);
</script>
<template>
    <figure class="ss-sales-trend">
        <figcaption><strong>{{ __('Daily net sales') }}</strong></figcaption>
        <div class="ss-chart-key"><span>{{ from }} – {{ to }}</span><span>{{ trend.previousFrom }} – {{ trend.previousTo }}</span></div>
        <div class="ss-chart-plot"><div class="ss-chart-scale"><span>{{ money(scale.max) }}</span><span>{{ money(scale.min) }}</span></div>
        <svg viewBox="0 0 600 120" preserveAspectRatio="none" role="img" :aria-label="__('Daily net sales compared with the previous period')">
            <line x1="0" x2="600" :y1="y(0)" :y2="y(0)" stroke="currentColor" opacity=".25" />
            <polyline v-for="index in [1, 0]" :key="index" :points="coordinates(lines[index])" fill="none" :stroke="index ? 'var(--ss-chart-previous)' : 'var(--ss-chart-current)'" :stroke-dasharray="index ? '5 4' : undefined" stroke-width="2.5" vector-effect="non-scaling-stroke" />
            <circle v-if="length === 1" v-for="index in [1, 0]" :key="'point'+index" cx="6" :cy="y(lines[index][0][1])" r="3" :fill="index ? 'var(--ss-chart-previous)' : 'var(--ss-chart-current)'" />
        </svg></div><div class="ss-chart-dates"><span>{{ from }}</span><span>{{ to }}</span></div>
        <details><summary>{{ __('Daily amounts') }}</summary>
            <table><thead><tr><th>{{ __('Period') }}</th><th>{{ __('Date') }}</th><th>{{ __('Net merchandise sales') }}</th></tr></thead>
                <tbody v-for="(values, label) in { current: trend.current, previous: trend.previous }" :key="label">
                    <tr v-for="date in Object.keys(values).sort()" :key="date"><td>{{ label === 'current' ? __('Current period') : __('Previous period') }}</td><td>{{ date }}</td><td>{{ money(values[date]) }}</td></tr>
                </tbody></table>
        </details>
    </figure>
</template>
