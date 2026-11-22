@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.shop'))
@section('content')
<header class="ss-page-head"><h1>{{ __('sellissimo::shop.shop') }}</h1></header>
@include('sellissimo::catalog-filters')
@include('sellissimo::catalog-response')
@include('sellissimo::catalog-interactions')
@endsection
