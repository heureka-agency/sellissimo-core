@if($policies)
<section class="ss-panel"><h2>{{ __('sellissimo::shop.return_policy') }}</h2>
@foreach($policies as $policy)<p>{{ $policy['title'] }} · {{ $policy['label'] }}</p>@endforeach
</section>
@endif
