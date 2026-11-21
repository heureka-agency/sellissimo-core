@if(!empty($accounts['login']))
<a class="ss-button ss-button--primary" href="{{ $accounts['login'] }}">{{ __('sellissimo::shop.sign_in') }}</a>
@else
<form class="ss-form" method="post" action="{{ route('statamic.login') }}">@csrf
    <input type="hidden" name="_redirect" value="{{ $url }}"><input type="hidden" name="_error_redirect" value="{{ request()->fullUrl() }}">
    @include('sellissimo::text-input', ['input' => ['name' => 'email', 'label' => __('sellissimo::shop.email'), 'type' => 'email', 'autocomplete' => 'username', 'value' => $email ?? '']])
    @include('sellissimo::text-input', ['input' => ['name' => 'password', 'label' => __('sellissimo::shop.account_password'), 'type' => 'password', 'autocomplete' => 'current-password']])
    <button>{{ __('sellissimo::shop.sign_in') }}</button>
</form>
@endif
@if(!empty($accounts['register']))
<p><a href="{{ $accounts['register'] }}">{{ __('sellissimo::shop.create_account') }}</a></p>
@elseif(\Statamic\Statamic::pro())
<details class="ss-block" @if($errors->getBag('user.register')->any()) open @endif>
    <summary>{{ __('sellissimo::shop.create_account') }}</summary>
    @if($errors->getBag('user.register')->any())
    <div role="alert"><ul>@foreach($errors->getBag('user.register')->unique() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form class="ss-form" action="{{ route('statamic.register') }}" method="post">@csrf
        <input type="hidden" name="_redirect" value="{{ $url }}"><input type="hidden" name="_error_redirect" value="{{ request()->fullUrl() }}">
        @include('sellissimo::text-input', ['input' => ['name' => 'name', 'label' => __('sellissimo::shop.full_name'), 'autocomplete' => 'name', 'required' => false, 'bag' => 'user.register']])
        @include('sellissimo::text-input', ['input' => ['name' => 'email', 'id' => 'register-email', 'label' => __('sellissimo::shop.email'), 'type' => 'email', 'autocomplete' => 'email', 'value' => $email ?? '', 'bag' => 'user.register']])
        @include('sellissimo::text-input', ['input' => ['name' => 'password', 'id' => 'register-password', 'label' => __('sellissimo::shop.account_password'), 'type' => 'password', 'autocomplete' => 'new-password', 'bag' => 'user.register']])
        @include('sellissimo::text-input', ['input' => ['name' => 'password_confirmation', 'label' => __('sellissimo::shop.confirm_password'), 'type' => 'password', 'autocomplete' => 'new-password', 'bag' => 'user.register']])
        <button>{{ __('sellissimo::shop.create_account') }}</button>
    </form>
</details>
@endif
