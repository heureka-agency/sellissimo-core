<form data-catalog-filters class="catalog-filters catalog-toolbar" role="search" action="{{ route('sellissimo.shop') }}">
    <input type="hidden" name="site" value="{{ $site }}">
    @if($errors->has('q'))@section('sellissimo.error.q', 'catalog-search')@endif
    @isset($filters['per_page'])<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endisset
    <div class="catalog-basic">
    <label>{{ __('sellissimo::shop.search_products') }}<input name="q" id="catalog-search" @if($errors->has('q')) aria-invalid="true" aria-describedby="catalog-search-error" @endif maxlength="150" value="{{ is_scalar($query = old('q', $filters['q'] ?? '')) ? $query : '' }}">@error('q')<small class="field-error" id="catalog-search-error">{{ $message }}</small>@enderror</label>
    @if($categories->isNotEmpty())<label>{{ __('sellissimo::shop.category') }}<select name="category"><option value="">{{ __('sellissimo::shop.all_categories') }}</option>@foreach($categories as $slug=>$name)<option value="{{ $slug }}" @selected(($filters['category'] ?? '') === $slug)>{{ $name }}</option>@endforeach</select></label>@endif
    <label>{{ __('sellissimo::shop.sort_by') }}<select name="sort"><option value="">{{ __('sellissimo::shop.featured_first') }}</option>@foreach(['price_asc','price_desc'] as $order)<option value="{{ $order }}" @selected(($filters['sort'] ?? '') === $order)>{{ __('sellissimo::shop.'.$order) }}</option>@endforeach</select></label>
    </div>
    <details @if($errors->hasAny(['price_min','price_max']) || isset($filters['in_stock']) || filled($filters['price_min'] ?? null) || filled($filters['price_max'] ?? null) || !empty($filters['attributes'])) open @endif>
        <summary>{{ __('sellissimo::shop.more_filters') }}</summary>
        <div class="catalog-filters">
            <label><input type="checkbox" name="in_stock" value="1" @checked($filters['in_stock'] ?? false)> {{ __('sellissimo::shop.in_stock_only') }}</label>
            @foreach(['price_min','price_max'] as $field)<label>{{ __('sellissimo::shop.'.$field) }} ({{ $currency }})<input inputmode="decimal" name="{{ $field }}" id="catalog-{{ $field }}" value="{{ is_scalar($bound = old($field, $filters[$field] ?? '')) ? $bound : '' }}" @if($errors->has($field)) aria-invalid="true" aria-describedby="catalog-{{ $field }}-error" @endif>
                @error($field)
                    @section('sellissimo.error.'.$field, 'catalog-'.$field)
                    <small class="field-error" id="catalog-{{ $field }}-error">{{ $message }}</small>
                @enderror</label>@endforeach
            @foreach($facets as $name=>$values)<label>{{ $name }}<select name="attributes[{{ $name }}]"><option value="">{{ __('sellissimo::shop.any_value') }}</option>@foreach($values as $value)<option value="{{ $value }}" @selected(($filters['attributes'][$name] ?? null) === $value)>{{ $value }}</option>@endforeach</select></label>@endforeach
        </div>
    </details>
    <button type="submit">{{ __('sellissimo::shop.apply_filters') }}</button>
</form>
<p data-catalog-error class="field-error" role="alert" hidden></p>
