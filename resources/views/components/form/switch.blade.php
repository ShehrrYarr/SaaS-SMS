@props(['name', 'label', 'hint' => null, 'live' => false, 'wrapper' => 'mb-4'])

@php($id = 'field-'.str_replace(['.', '_'], '-', $name))

<div class="{{ $wrapper }}">
    <div class="form-check form-switch">
        <input type="checkbox" role="switch" id="{{ $id }}"
               @if ($live) wire:model.live="{{ $name }}" @else wire:model="{{ $name }}" @endif
               {{ $attributes->class(['form-check-input', 'is-invalid' => $errors->has($name)]) }}>
        <label class="form-check-label" for="{{ $id }}">{{ $label }}</label>
        @error($name)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
