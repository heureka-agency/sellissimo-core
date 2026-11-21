@php
    $name = $input['name'];
    $id = $input['id'] ?? 'ss-'.$name;
    $type = $input['type'] ?? 'text';
    $bag = $errors->getBag($input['bag'] ?? 'default');
    $error = $bag->first($name);
    $value = $type === 'password' ? '' : old($name, $input['value'] ?? '');
@endphp
@if($error && ($input['bag'] ?? 'default') === 'default')
    @section('sellissimo.error.'.$name, $id)
    @section('sellissimo.label.'.$name, $input['label'])
@endif
<label for="{{ $id }}">
    {{ $input['label'] }}
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        @if(isset($input['autocomplete'])) autocomplete="{{ $input['autocomplete'] }}" @endif
        @if(isset($input['maxlength'])) maxlength="{{ $input['maxlength'] }}" @endif
        @if($type !== 'password') value="{{ is_scalar($value) ? $value : '' }}" @endif
        @required($input['required'] ?? true)
        @if($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
    @if($error)<small class="field-error" id="{{ $id }}-error">{{ $error }}</small>@endif
</label>
