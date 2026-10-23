@props(['name', 'label' => null, 'required' => false, 'hint' => null, 'rows' => 3, 'wrapper' => 'mb-4'])

@php($id = 'field-'.str_replace(['.', '_'], '-', $name))

<div class="{{ $wrapper }}">
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif

    <textarea id="{{ $id }}" rows="{{ $rows }}" wire:model="{{ $name }}"
              @if ($required) required aria-required="true" @endif
              @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
              {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}></textarea>

    @error($name)
        <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
