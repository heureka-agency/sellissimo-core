@if($cart && session('sellissimo.cart.'.$site.'.retry') && session('sellissimo.before_retry.'.$site.'.cart'))
<form class="ss-context" action="{{ route('sellissimo.payment.retry.leave', $cart) }}" method="post">@csrf
<input type="hidden" name="site" value="{{ $site }}"><button class="ss-button--quiet">{{ __('sellissimo::shop.retry_leave') }}</button>
</form>
@endif
