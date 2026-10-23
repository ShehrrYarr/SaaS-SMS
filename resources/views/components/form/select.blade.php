{{--
    <x-form.select name="status" :label="__('Status')" :options="App\Enums\AcademicPeriodStatus::options()" required />
    :options is value => label. Pass :placeholder="null" to omit the empty option.
--}}
@props(['name', 'label' => null, 'options' => [], 'placeholder' => __('Select…'), 'required' => false, 'hint' => null, 'live' => false, 'wrapper' => 'mb-4'])

@php($id = 'field-'.str_replace(['.', '_'], '-', $name))

<div class="{{ $wrapper }}">
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif

    <select id="{{ $id }}"
            @if ($live) wire:model.live="{{ $name }}" @else wire:model="{{ $name }}" @endif
            @if ($required) required aria-required="true" @endif
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
            {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $optionLabel)
            <option value="{{ $value }}">{{ $optionLabel }}</option>
        @endforeach
    </select>

    @error($name)
        <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
