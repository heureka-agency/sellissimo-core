@if($images)
    @php
        $lightbox = config('sellissimo.product_gallery.lightbox', true);
        $hoverZoom = config('sellissimo.product_gallery.hover_zoom', true);
    @endphp
    @if($lightbox || $hoverZoom)
        @pushOnce('head')
            @include('sellissimo::product-gallery-styles')
        @endPushOnce
    @endif
    <div class="product-gallery" data-product-gallery @if($hoverZoom) data-hover-zoom @endif>
        @foreach($images as $image)
            <a href="{{ $image['url'] }}" data-gallery-image aria-label="{{ __('sellissimo::shop.open_product_image', ['number' => $loop->iteration, 'description' => $image['alt'] ?: $product['product_title']]) }}">
                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" @if(!$loop->first) loading="lazy" @endif>
                @if($lightbox && $loop->first)
                    <span class="ss-gallery-expand" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20" aria-hidden="true" focusable="false"><path d="M9 4H4v5m11-5h5v5M4 15v5h5m11-5v5h-5"/></svg>
                    </span>
                @endif
            </a>
        @endforeach
        @if($lightbox)
            <dialog class="ss-gallery-dialog" data-gallery-dialog aria-label="{{ __('sellissimo::shop.product_image_viewer') }}">
                <div class="ss-gallery-tools">
                    @if(count($images) > 1)
                        <span data-gallery-count role="status"></span>
                        <button type="button" data-gallery-previous aria-label="{{ __('sellissimo::shop.previous_product_image') }}">&#8592;</button>
                        <button type="button" data-gallery-next aria-label="{{ __('sellissimo::shop.next_product_image') }}">&#8594;</button>
                    @endif
                    <button type="button" data-gallery-zoom aria-pressed="false">{{ __('sellissimo::shop.zoom_product_image') }}</button>
                    <button type="button" data-gallery-close autofocus aria-label="{{ __('sellissimo::shop.close_image_viewer') }}">&#215;</button>
                </div>
                <div class="ss-gallery-stage" data-gallery-stage tabindex="0" role="region" aria-label="{{ __('sellissimo::shop.product_image_viewer') }}">
                    <div class="ss-gallery-canvas"><img data-gallery-current alt="" draggable="false"></div>
                </div>
            </dialog>
        @endif
        @if($lightbox || $hoverZoom)
            @include('sellissimo::product-gallery-script')
        @endif
    </div>
@endif
