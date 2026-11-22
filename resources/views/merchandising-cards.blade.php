@if($items)
<section class="ss-merchandising">
    <h2>{{ $heading }}</h2>
    <div class="catalog-grid">
        @foreach($items as $item)
            <article class="catalog-product">
                @if($image = $item['images'][0] ?? null)
                    <div class="catalog-image"><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy"></div>
                @endif
                <h3><a href="{{ $item['url'] }}">{{ $item['product_title'] }}</a></h3>
                <p class="catalog-price">{{ \Sellissimo\Core\Money::display($item['price'], $item['currency']) }}</p>
            </article>
        @endforeach
    </div>
</section>
@endif
