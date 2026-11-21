<ui-widget title="{{ __('Store activity') }}" icon="shopping-cart" class="ss-dashboard-widget">
    <template #actions>
        <ui-dropdown align="end">
            <template #trigger>
                <ui-button size="sm" variant="ghost" icon-append="chevron-down" text="{{ __('Last :days days', ['days' => $days]) }}"></ui-button>
            </template>
            <ui-dropdown-menu>
                @foreach ([7, 30, 90] as $period)
                    <ui-dropdown-item
                        as="a"
                        href="{{ cp_route('dashboard', array_replace(request()->query(), ['sellissimo_days' => $period])) }}"
                        text="{{ __('Last :days days', ['days' => $period]) }}"
                        @if ($period === $days) icon="checkmark" aria-current="true" @endif
                    ></ui-dropdown-item>
                @endforeach
            </ui-dropdown-menu>
        </ui-dropdown>
    </template>

    <div class="ss-dashboard-body">
        @if ($scope)
            <p class="ss-dashboard-scope"><ui-icon name="site" aria-hidden="true"></ui-icon><span v-pre>{{ $scope }}</span></p>
        @endif

        <div @class(['ss-activity-panels', 'ss-activity-split' => $customers !== null && $recent?->isNotEmpty()])>
            @if ($customers !== null)
                <div class="ss-activity-summary">
                    <dl class="ss-activity-metrics" v-pre>
                        <div>
                            <dt>{{ __('New customers') }}</dt>
                            <dd>{{ $customers['new'] }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Returning customers') }}</dt>
                            <dd>{{ $customers['returning'] }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Refunds') }}</dt>
                            <dd>
                                @forelse ($refunds as $currency => $total)
                                    <div>{{ \Sellissimo\Core\Money::display($total, $currency) }} <small>{{ $currency }}</small></div>
                                @empty
                                    <span aria-hidden="true">0</span><span class="sr-only">{{ __('No settled refunds during this period.') }}</span>
                                @endforelse
                            </dd>
                        </div>
                    </dl>
                </div>
            @endif

            @if ($recent !== null)
                <section class="ss-activity-recent">
                    <h3 class="ss-activity-heading">{{ __('Recent orders') }}</h3>
                    @if ($recent->isEmpty())
                        <p class="ss-dashboard-empty"><ui-icon name="shopping-cart" aria-hidden="true"></ui-icon><span>{{ __('No orders during this period.') }}</span></p>
                    @else
                        <ul class="ss-activity-orders" role="list">
                            @foreach ($recent as $order)
                                <li>
                                    <a href="{{ $order['url'] }}" class="ss-activity-order">
                                        <span class="ss-activity-reference" v-pre>
                                            <strong><span class="sr-only">{{ __('Order') }} </span>{{ $order['number'] }}</strong>
                                            <span class="ss-activity-date">{{ $order['date'] }}</span>
                                        </span>
                                        <span class="ss-activity-status"><sellissimo-payment-badge status="{{ $order['payment'] }}"></sellissimo-payment-badge></span>
                                        <span class="ss-activity-total" v-pre><span class="sr-only">{{ __('Total') }} </span>{{ $order['total'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </div>
    </div>
</ui-widget>
