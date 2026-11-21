@foreach($definition['fields'] as $key => $field)
@php
    $path = 'checkout_steps.'.$handle.'.'.$key;
    $name = 'checkout_steps['.$handle.']['.$key.']';
    $value = old($path, $quote['checkout_steps'][$handle]['fields'][$key]['value'] ?? '');
    $id = 'checkout-'.$handle.'-'.$key;
@endphp
@if($errors->has($path))
    @section('sellissimo.error.'.$path, $id)
    @section('sellissimo.label.'.$path, $field['label'])
@endif
<label>{{ $field['label'] }}
@if($field['type'] === 'select')
<select id="{{ $id }}" name="{{ $name }}" @required($field['required']) @if($errors->has($path)) aria-invalid="true" aria-describedby="error-{{ $handle }}-{{ $key }}" @endif>
<option value="">{{ __('sellissimo::shop.choose_option') }}</option>
@foreach($field['options'] as $code => $label)<option value="{{ $code }}" @selected((string) $code === (string) $value)>{{ $label }}</option>@endforeach
</select>
@else
<input id="{{ $id }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ $value }}" maxlength="500" @required($field['required']) @if($errors->has($path)) aria-invalid="true" aria-describedby="error-{{ $handle }}-{{ $key }}" @endif>
@endif
@error($path)<small class="field-error" id="error-{{ $handle }}-{{ $key }}">{{ $message }}</small>@enderror
</label>
@endforeach
