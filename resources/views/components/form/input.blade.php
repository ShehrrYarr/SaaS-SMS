{{--
    <x-form.input name="name" :label="__('Name')" required />
    Binds wire:model to `name` (the component property) and shows its validation error.
    Extra attributes (placeholder, min, autocomplete...) go on the <input>.
--}}
@props(['name', 'label' => null, 'type' => 'text', 'required' => false, 'hint' => null, 'live' => false, 'wrapper' => 'mb-4'])

@php($id = 'field-'.str_replace(['.', '_'], '-', $name))

<div class="{{ $wrapper }}">
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif

    <input type="{{ $type }}" id="{{ $id }}"
           @if ($live) wire:model.live.blur="{{ $name }}" @else wire:model="{{ $name }}" @endif
           @if ($required) required aria-required="true" @endif
           @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
           {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>

    @error($name)
        <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
