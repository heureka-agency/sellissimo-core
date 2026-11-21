@foreach($fields as $handle => $field)
@php
    $path = $prefix.'.'.$handle;
    $name = $prefix.'['.$handle.']';
    $submitted = !isset($p['sku']) || old('sku') === $p['sku'];
    $previous = $submitted ? old($path, $values[$handle] ?? '') : ($values[$handle] ?? '');
    $value = is_scalar($previous) ? $previous : '';
    $error = $submitted ? ($errors->first($path) ?: $errors->first($handle)) : '';
    $id = 'field-'.$prefix.'-'.($p['sku'] ?? 'item').'-'.$handle;
    $state = new \Illuminate\View\ComponentAttributeBag($error ? ['aria-invalid' => 'true', 'aria-describedby' => $id.'-error'] : []);
@endphp
@if($error)
    @section('sellissimo.error.'.($errors->has($path) ? $path : $handle), $id)
    @section('sellissimo.label.'.($errors->has($path) ? $path : $handle), $field['label'])
@endif
@if($field['type'] === 'radio')
<fieldset class="ss-radio-field" id="{{ $id }}" tabindex="-1">
    <legend>{{ $field['label'] }}</legend>
    @if(!$field['required'])
    <label><input type="radio" name="{{ $name }}" value="" @checked($value === '') {{ $state }}> {{ __('sellissimo::shop.no_selection') }}</label>
    @endif
    @foreach($field['options'] as $key => $label)
    <label><input type="radio" name="{{ $name }}" value="{{ $key }}" @checked((string)$value === (string)$key) @required($field['required']) {{ $state }}> {{ $label }}</label>
    @endforeach
    @if($error)<small class="field-error" id="{{ $id }}-error">{{ $error }}</small>@endif
</fieldset>
@else
<label for="{{ $id }}">{{ $field['label'] }}
    @if($field['type'] === 'assets')
    @if($value)<input type="hidden" name="{{ $name }}" value="{{ $value }}"><small>{{ $filenames[$value] ?? __('sellissimo::shop.file_attached') }}</small>@endif
    <input type="file" id="{{ $id }}" name="{{ $name }}" accept=".pdf,.png,.jpg,.jpeg,.webp" @required($field['required'] && !$value) {{ $state }}>
    @elseif($field['type'] === 'select')
    <select id="{{ $id }}" name="{{ $name }}" @required($field['required']) {{ $state }}>
        <option value="">{{ __('sellissimo::shop.choose_option') }}</option>
        @foreach($field['options'] as $key=>$label)<option value="{{ $key }}" @selected((string)$value === (string)$key)>{{ $label }}</option>@endforeach
    </select>
    @elseif($field['type'] === 'toggle')
    <input type="hidden" name="{{ $name }}" value="0"><input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked($value) {{ $state }}>
    @elseif($field['type'] === 'textarea')
    <textarea id="{{ $id }}" name="{{ $name }}" @required($field['required']) {{ $state }}>{{ $value }}</textarea>
    @else
    <input type="{{ ['integer'=>'number', 'date'=>'date'][$field['type']] ?? 'text' }}" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @required($field['required']) {{ $state }}>
    @endif
    @if($error)<small class="field-error" id="{{ $id }}-error">{{ $error }}</small>@endif
</label>
@endif
@endforeach
