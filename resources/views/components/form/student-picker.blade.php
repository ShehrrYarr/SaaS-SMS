{{--
    Search box for the PicksStudent trait (App\Livewire\Concerns\PicksStudent).

        <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" />

    :locked hides the "Change" button (the student was fixed by the caller).
--}}
@props(['results', 'selected' => '', 'picked' => '', 'search' => '', 'label' => __('Student'), 'required' => true, 'locked' => false, 'wrapper' => 'mb-4'])

<div class="{{ $wrapper }} position-relative">
    <label class="form-label" for="field-student-search">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @if ($picked !== '' && $search === '')
        <div class="input-group">
            <span @class(['form-control bg-light text-truncate', 'is-invalid' => $errors->has('student_id')])>{{ $selected }}</span>
            @unless ($locked)
                <button type="button" class="btn btn-light" wire:click="clearStudent">{{ __('Change') }}</button>
            @endunless
        </div>
    @else
        <input type="search" id="field-student-search" autocomplete="off" wire:model.live.debounce.300ms="studentSearch"
               @class(['form-control', 'is-invalid' => $errors->has('student_id')]) placeholder="{{ __('Search by name, admission number or a parent\'s phone…') }}">
        @if (mb_strlen(trim($search)) >= 2)
            <div class="list-group position-absolute start-0 end-0 shadow-sm z-3 mt-1">
                @forelse ($results as $result)
                    <button type="button" class="list-group-item list-group-item-action" wire:click="pickStudent({{ $result->id }})" wire:key="pick-{{ $result->id }}">
                        <span class="fw-medium">{{ $result->user?->name }}</span>
                        <small class="text-muted ms-1">{{ collect([$result->admission_number, $result->placementLabel()])->filter()->implode(' · ') }}</small>
                        @unless ($result->isActive())
                            <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $result->status->label() }}</span>
                        @endunless
                    </button>
                @empty
                    <div class="list-group-item text-muted fs-13">{{ __('No student matches.') }}</div>
                @endforelse
            </div>
        @endif
    @endif
    @error('student_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
</div>
