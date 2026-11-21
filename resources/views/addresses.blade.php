@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.my_address_book'))
@section('surface', 'ss-document')
@section('content')
<p class="ss-context"><a href="{{ route('sellissimo.history', ['site' => $site]) }}">{{ __('sellissimo::shop.my_orders') }}</a></p>
<h1>{{ __('sellissimo::shop.my_address_book') }}</h1>
@if(count($sites)>1)
<form class="inline ss-page-head" method="get" action="{{ route('sellissimo.addresses') }}">
    <label>{{ __('sellissimo::shop.store_site') }}
        <select name="site">@foreach($sites as $code=>$name)<option value="{{ $code }}" @selected($code===$site)>{{ $name }}</option>@endforeach</select>
    </label>
    <button>{{ __('sellissimo::shop.view_addresses') }}</button>
</form>
@endif
@foreach([...$book['entries'], ['id'=>null,'label'=>'','address'=>[]]] as $entry)
@php($editing = $errors->any() && old('action') === 'save' && old('id') === $entry['id'])
<details class="ss-panel" @if($editing || (!$entry['id'] && !$book['entries'])) open @endif>
    <summary>
        {{ $entry['label'] ?: __('sellissimo::shop.add_an_address') }}
        @if($entry['id'] && $entry['id']===$book['billing'])<span class="ss-badge">{{ __('sellissimo::shop.billing_default') }}</span>@endif
        @if($entry['id'] && $entry['id']===$book['shipping'])<span class="ss-badge">{{ __('sellissimo::shop.shipping_default') }}</span>@endif
    </summary>
    <form class="ss-form" method="post" action="{{ route('sellissimo.addresses',['site'=>$site]) }}">@csrf
        <input type="hidden" name="revision" value="{{ $book['revision'] }}"><input type="hidden" name="action" value="save">
        @if($entry['id'])<input type="hidden" name="id" value="{{ $entry['id'] }}">@endif
        <label>{{ __('sellissimo::shop.address_label') }}
            @if($editing && $errors->has('label')) @section('sellissimo.error.label', 'book-label-'.($entry['id'] ?? 'new')) @endif
            <input id="book-label-{{ $entry['id'] ?? 'new' }}" name="label" required maxlength="80" value="{{ $editing ? old('label') : $entry['label'] }}" @if($editing && $errors->has('label')) aria-invalid="true" aria-describedby="book-label-error" @endif>
            @if($editing)@error('label')<small id="book-label-error" class="field-error">{{ $message }}</small>@enderror @endif
        </label>
        <fieldset class="address-grid">@include('sellissimo::address-inputs',['field'=>'address','address'=>$entry['address'], 'useOldInput'=>$editing, 'idPrefix'=>'book-'.($entry['id'] ?? 'new')])</fieldset>
        <label><span><input type="checkbox" name="billing" value="1" @checked($editing ? old('billing') : ($entry['id'] && $book['billing']===$entry['id']))> {{ __('sellissimo::shop.default_billing_address') }}</span></label>
        <label><span><input type="checkbox" name="shipping" value="1" @checked($editing ? old('shipping') : ($entry['id'] && $book['shipping']===$entry['id']))> {{ __('sellissimo::shop.default_shipping_address') }}</span></label>
        <button>{{ __('sellissimo::shop.save_address') }}</button>
    </form>
    @if($entry['id'])
    <form class="ss-block" method="post" action="{{ route('sellissimo.addresses',['site'=>$site]) }}">@csrf
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="{{ $entry['id'] }}"><input type="hidden" name="revision" value="{{ $book['revision'] }}">
        <button class="ss-button--quiet">{{ __('sellissimo::shop.remove_address') }}</button>
    </form>
    @endif
</details>
@endforeach
@endsection
