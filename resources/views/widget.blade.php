<ui-widget class="ss-dashboard-widget" title="{{ __($title) }}" icon="{{ $icon }}">
    @if ($url)
        <template #actions>
            <ui-button size="sm" variant="ghost" href="{{ $url }}" text="{{ __($action) }}"></ui-button>
        </template>
    @endif

    <div @class(['ss-dashboard-body', 'ss-dashboard-body-empty' => $rows->isEmpty()])>
        @if ($scope)
            <p class="ss-dashboard-scope">
                <ui-icon name="site" aria-hidden="true"></ui-icon>
                <span v-pre>{{ $scope }}</span>
            </p>
        @endif

        @if ($rows->isEmpty())
            <p class="ss-dashboard-empty">
                <ui-icon name="{{ ($layout ?? null) === 'sales' ? 'mail-inbox-content' : 'checkmark' }}" aria-hidden="true"></ui-icon>
                <span v-pre>{{ __($empty) }}</span>
            </p>
        @elseif (($layout ?? null) === 'sales')
            <div class="ss-sales-list" v-pre>
                @foreach ($rows as [$currency, $paid, $refunded])
                    <section class="ss-sales-currency">
                        <h3 class="ss-sales-code">{{ $currency }}</h3>
                        <dl class="ss-sales-metrics">
                            <div class="ss-sales-paid">
                                <dt>{{ __('Paid sales') }}</dt>
                                <dd>{{ $paid }}</dd>
                            </div>
                            <div class="ss-sales-refunds">
                                <dt>{{ __('Refunds') }}</dt>
                                <dd>{{ $refunded }}</dd>
                            </div>
                        </dl>
                    </section>
                @endforeach
            </div>
        @elseif (($layout ?? null) === 'stock')
            <ul class="ss-dashboard-list" v-pre>
                @foreach ($rows as [$product, $available])
                    <li class="ss-dashboard-row">
                        <a class="ss-dashboard-item" href="{{ $product['url'] }}">
                            <span class="ss-dashboard-title">{{ filled($product['title']) ? $product['title'] : $product['label'] }}</span>
                            @if (filled($product['title']) && $product['title'] !== $product['label'])
                                <span class="ss-dashboard-meta"><span class="sr-only">{{ __('SKU') }} </span>{{ $product['label'] }}</span>
                            @endif
                        </a>
                        <span class="ss-dashboard-quantity" data-state="{{ $available <= 0 ? 'empty' : 'low' }}">
                            {{ $available === 0 ? __('Out of stock') : __('sellissimo::shop.count_available', ['count' => $available]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @elseif (($layout ?? null) === 'shipments')
            <ul class="ss-dashboard-list" v-pre>
                @foreach ($rows as [$order, $placed])
                    <li class="ss-dashboard-row">
                        @php($element = $order['url'] ? 'a' : 'span')
                        <{{ $element }} class="ss-dashboard-item" @if ($order['url']) href="{{ $order['url'] }}" @endif>
                            <span class="ss-dashboard-title"><span class="sr-only">{{ __('Order') }} </span>{{ $order['label'] }}</span>
                            @if ($order['site'])
                                <span class="ss-dashboard-meta">{{ $order['site'] }}</span>
                            @endif
                        </{{ $element }}>
                        <time class="ss-dashboard-date" datetime="{{ $order['date'] }}">
                            <span class="sr-only">{{ __('Order date') }} </span>{{ $placed }}
                        </time>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="overflow-x-auto">
                <ui-table>
                    <ui-table-columns>@foreach($columns as $column)<ui-table-column>{{ __($column) }}</ui-table-column>@endforeach</ui-table-columns>
                    <ui-table-rows>@foreach($rows as $row)
                        <ui-table-row>@foreach($row as $value)<ui-table-cell>@if(is_array($value) && $value['url'])<a href="{{ $value['url'] }}" v-pre>{{ $value['label'] }}</a>@else<span v-pre>{{ is_array($value) ? $value['label'] : $value }}</span>@endif</ui-table-cell>@endforeach</ui-table-row>
                    @endforeach</ui-table-rows>
                </ui-table>
            </div>
        @endif
    </div>
</ui-widget>
